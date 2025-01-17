<?php
namespace MediaWiki\Extension\DataMaps\Content\DataConstraints;

use MediaWiki\Extension\DataMaps\Content\MapVersionInfo;
use Status;
use stdClass;

class AssociationStringGroupExistsConstraint implements DataConstraint {
    private const MESSAGE = 'datamap-validate-constraint-groupexists';

    public function getDependencies(): array {
        return [];
    }

    public function run( Status $status, MapVersionInfo $version, stdClass $data ): bool {
        if ( !isset( $data->markers ) ) {
            return true;
        }

        $result = true;

        $groupIds = array_keys( (array)( $data->groups ?? new stdClass() ) );
        foreach ( array_keys( (array)$data->markers ) as $assocStr ) {
            $assocGroup = explode( ' ', $assocStr, 2 )[0];
            if ( !in_array( $assocGroup, $groupIds ) ) {
                $status->error( self::MESSAGE, "/markers/$assocStr", $assocGroup );
                $result = false;
            }
        }

        return $result;
    }
}
