<?php
namespace MediaWiki\Extension\DataMaps\Content\DataConstraints;

use MediaWiki\Extension\DataMaps\Content\MapVersionInfo;
use Status;
use stdClass;

class LayerIdNoOverlapConstraint implements DataConstraint {
    private const DECLARATION_OVERLAP_MESSAGE = 'datamap-validate-constraint-layerdecloverlap';
    private const MARKER_OVERLAP_MESSAGE = 'datamap-validate-constraint-assocgroupoverlap';
    private const MARKER_NONUNIQUE_MESSAGE = 'datamap-validate-constraint-assocnonuniqoverlap';

    public function getDependencies(): array {
        return [];
    }

    public function run( Status $status, MapVersionInfo $version, stdClass $data ): bool {
        $result = true;

        if ( isset( $data->groups ) && isset( $data->categories ) ) {
            $overlap = array_intersect(
                array_keys( (array)$data->groups ),
                array_keys( (array)$data->categories )
            );
            foreach ( $overlap as $badLayer ) {
                $status->error( self::DECLARATION_OVERLAP_MESSAGE, "/groups/$badLayer", "/categories/$badLayer" );
                $result = false;
            }
        }

        if ( isset( $data->markers ) ) {
            $groupIds = array_keys( (array)( $data->groups ?? new stdClass() ) );

            foreach ( array_keys( (array)$data->markers ) as $assocStr ) {
                $assocLayers = explode( ' ', $assocStr );

                if ( count( array_unique( $assocLayers ) ) !== count( $assocLayers ) ) {
                    $status->error( self::MARKER_NONUNIQUE_MESSAGE, "/markers/$assocStr" );
                    $result = false;
                }

                $assocGroups = array_filter( $assocLayers, fn ( $item ) => in_array( $item, $groupIds ) );
                if ( count( $assocGroups ) > 1 ) {
                    $formatted = implode( ', ', array_map( fn ( $item ) => "<code>$item</code>", $assocGroups ) );
                    $status->error( self::MARKER_OVERLAP_MESSAGE, "/markers/$assocStr", $formatted );
                    $result = false;
                }
            }
        }

        return $result;
    }
}
