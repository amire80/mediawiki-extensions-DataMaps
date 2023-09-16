<?php
namespace MediaWiki\Extension\DataMaps\Rendering;

use MapCacheLRU;
use MediaWiki\Extension\DataMaps\Data\DataMapSpec;
use MediaWiki\Extension\DataMaps\Data\MapSettingsSpec;
use MediaWiki\Extension\DataMaps\Data\MarkerGroupSpec;
use MediaWiki\Extension\DataMaps\Data\MarkerSpec;
use MediaWiki\Extension\DataMaps\ExtensionConfig;
use MediaWiki\Extension\DataMaps\Rendering\Utils\DataMapFileUtils;
use MediaWiki\MediaWikiServices;
use Parser;
use ParserOptions;
use ThumbnailImage;
use Title;

class MarkerProcessor {
    private const POPUP_IMAGE_WIDTH = 288;
    private const POPUP_IMAGE_HEIGHT_MAX = 300;
    private const MAX_LRU_SIZE = 128;

    private Parser $parser;
    private ParserOptions $parserOptions;
    private ExtensionConfig $config;
    private Title $title;
    private DataMapSpec $dataMap;
    private ?array $filter;
    private bool $isSearchEnabled;

    private bool $isParserDirty = true;
    private bool $useLocalParserCache = true;
    private ?MapCacheLRU $localParserCache = null;

    private bool $collectTimings = false;
    public $timeInParser = 0;

    // TODO: needs a factory

    public function __construct( Title $title, DataMapSpec $dataMap, ?array $filter ) {
        $this->parser = MediaWikiServices::getInstance()->getParser();
        $this->parserOptions = ParserOptions::newFromAnon();
        $this->config = MediaWikiServices::getInstance()->get( ExtensionConfig::SERVICE_NAME );
        $this->title = $title;
        $this->dataMap = $dataMap;
        $this->filter = $filter;
        $this->isSearchEnabled = $this->dataMap->getSettings()->getSearchMode() !== MapSettingsSpec::SM_NONE;
        // Pull configuration options
        $this->useLocalParserCache = $this->config->shouldCacheWikitextInProcess();
        $this->collectTimings = $this->config->shouldApiReturnProcessingTime();
        // Initialise the LRU
        if ( $this->useLocalParserCache ) {
            $this->localParserCache = new MapCacheLRU( self::MAX_LRU_SIZE );
        }
        // Configure the wikitext parser
        $this->parserOptions->setAllowSpecialInclusion( false );
        $this->parserOptions->setExpensiveParserFunctionLimit( 5 );
        $this->parserOptions->setInterwikiMagic( false );
        $this->parserOptions->setMaxIncludeSize( $this->config->getParserExpansionLimit() );
    }

    public function processAll(): array {
        $results = [];

        // Creating a marker model backed by an empty object, as it will later get reassigned to actual data to avoid
        // creating thousands of small, very short-lived (only one at a time) objects
        $marker = new MarkerSpec( new \stdclass() );

        $this->dataMap->iterateRawMarkerMap( function ( string $layers, array $rawCollection ) use ( &$results, &$marker ) {
            // If filters were specified, check if there is any overlap between the filters list and skip the marker set
            if ( $this->filter !== null && empty( array_intersect( $this->filter, explode( ' ', $layers ) ) ) ) {
                return;
            }

            $subResults = [];
            foreach ( $rawCollection as &$rawMarker ) {
                $marker->reassignTo( $rawMarker );
                $subResults[] = $this->processOne( $marker );
            }

            if ( !empty( $subResults ) ) {
                $results[$layers] = $subResults;
            }
        } );

        return $results;
    }

    public function processOne( MarkerSpec $marker ): array {
        // Flag parser state as requiring a cleaning
        $this->isParserDirty = true;
        // Coordinates
        $converted = [
            $marker->getLatitude(),
            $marker->getLongitude()
        ];
        // Rich data
        $slots = [];

        // Custom persistent ID
        if ( $marker->getCustomPersistentId() != null ) {
            $slots['uid'] = $marker->getCustomPersistentId();
        }

        // Popup title
        if ( $marker->getLabel() != null ) {
            $slots['label'] = $this->stripParagraphTags( $this->parseText( $marker, $marker->getLabel() ) );
        }

        // Popup description
        if ( $marker->getDescription() != null ) {
            $slots['desc'] = $this->parseMultilineText( $marker, $marker->getDescription() );
        }

        // Icon override
        if ( $marker->getCustomIcon() !== null ) {
            // TODO: needs to be group aware so size can be chosen properly
            $slots['icon'] = DataMapFileUtils::getMarkerIconUrl( $marker->getCustomIcon(),
                MarkerGroupSpec::DEFAULT_ICON_SIZE[ 0 ] );
        }

        // Popup image thumbnail link
        if ( $marker->getPopupImage() != null ) {
            $thumb = DataMapFileUtils::transformScaledImage( $marker->getPopupImage(), [
                'width' => self::POPUP_IMAGE_WIDTH,
                'height' => self::POPUP_IMAGE_HEIGHT_MAX,
            ] );
            $isActualThumb = $thumb instanceof ThumbnailImage;
            // TODO: these sizes are not valid for SVGs and are dependent on media handler support. perhaps UI should
            //       handle thumbnail sizing calcs on its own.
            $slots['image'] = [
                $thumb->getURL(),
                ( $isActualThumb ? $thumb->getFile() : $thumb )->getWidth(),
                ( $isActualThumb ? $thumb->getFile() : $thumb )->getHeight(),
                $thumb->getHeight(),
                $thumb->getWidth(),
            ];
        }

        // Related article title
        if ( $marker->getRelatedArticle() != null ) {
            $slots['article'] = $marker->getRelatedArticle();
        }

        // Search keywords
        if ( $this->isSearchEnabled ) {
            if ( !$marker->isIncludedInSearch() ) {
                $slots['search'] = 0;
            } elseif ( $marker->getSearchKeywords() != null ) {
                $keywords = $marker->getSearchKeywords();
                if ( $this->canImplodeSearchKeywords( $keywords ) ) {
                    $keywords = implode( ' ', $keywords );
                }
                $slots['search'] = $keywords;
            }
        }

        // Insert slots if any data has been added
        if ( !empty( $slots ) ) {
            $converted[] = $slots;
        }

        return $converted;
    }

    public static function shouldParseString( MarkerSpec $marker, string $text ): bool {
        $mIsWikitext = $marker->isWikitext();
        if ( $mIsWikitext === false ) {
            return false;
        }
        return $mIsWikitext || preg_match( "/\{\{|\[\[|\[h|\'\'|\{\||<\w+|&[\d\w]+/", $text ) === 1;
    }

    private function parseWikitext( string $text ): string {
        // Look up in local cache if enabled
        if ( $this->useLocalParserCache && $this->localParserCache->has( $text ) ) {
            return $this->localParserCache->get( $text );
        }

        $timeStart = 0;
        if ( $this->collectTimings ) {
            $timeStart = hrtime( true );
        }

        // Call the parser
        $out = $this->parser->parse( $text, $this->title, $this->parserOptions, false, $this->isParserDirty )
            ->getText( [
                'unwrap' => true,
                'allowTOC' => false,
                'includeDebugInfo' => false
            ] );
        // Mark as clean to avoid clearing state again
        $this->isParserDirty = false;

        if ( $this->collectTimings ) {
            $this->timeInParser += hrtime( true ) - $timeStart;
        }

        // Store in local cache if enabled
        if ( $this->useLocalParserCache ) {
            $this->localParserCache->set( $text, $out );
        }

        return $out;
    }

    private function parseText( MarkerSpec $marker, string $text ): string {
        if ( self::shouldParseString( $marker, $text ) ) {
            return $this->parseWikitext( $text );
        }

        $result = wfEscapeWikiText( trim( $text ) );
        $result = '<p>' . preg_replace( '/(\n&#10;)+/', '</p><p>', $result ) . '</p>';
        return $result;
    }

    private function parseMultilineText( MarkerSpec $marker, /*array|string*/ $text ): string {
        if ( is_array( $text ) ) {
            $text = implode( "\n", $text );
        }
        return $this->parseText( $marker, $text );
    }

    private function stripParagraphTag( string $text ): string {
        if ( str_starts_with( $text, '<p>' ) ) {
            $text = substr( $text, 3 );
        }
        if ( str_ends_with( $text, '</p>' ) ) {
            $text = substr( $text, 0, strlen( $text ) - 4 );
        }
        return $text;
    }

    private function stripParagraphTags( string $text ): string {
        return trim( preg_replace( '/<\/?p>/', ' ', $text ) ?? $text );
    }

    private function canImplodeSearchKeywords( $keywords ): bool {
        if ( is_array( $keywords ) ) {
            foreach ( $keywords as &$item ) {
                if ( !is_string( $item ) ) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }
}
