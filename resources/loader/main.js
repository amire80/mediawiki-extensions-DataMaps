const DataMap = require( './map.js' ),
    initialisedMaps = [];


function initialiseMapFromStore( id, $root ) {
    const config = mw.config.get( 'dataMaps' )[id];

    // Broadcast `beforeInitialisation` event that gadgets can register to
    mw.hook( `ext.ark.datamaps.beforeInitialisation.${id}` ).fire( config );

    // Set the map up
    const map = new DataMap( id, $root, config );

    // Push onto internal tracking list
    initialisedMaps.push( map );

    // Request markers from the API
    map.streamMarkersIn( map.config.pageName, map.config.version, map.dataSetFilters,
        () => map.$status.hide(),
        () => map.$status.show().html( mw.msg( 'datamap-error-dataload' ) ).addClass( 'error' ) );

    // Broadcast `afterInitialisation` event
    mw.hook( `ext.ark.datamaps.afterInitialisation.${id}` ).fire( map );

    return map;
}


// Begin initialisation once the document is loaded
mw.hook( 'wikipage.content' ).add( $content => {
    const ids = Object.keys( mw.config.get( 'dataMaps' ) );

    // Broadcast all map IDs so gadgets can register to 
    mw.hook( 'ext.ark.datamaps.broadcastMaps' ).fire( ids );

    // Run initialisation for every map, followed by events for gadgets to listen to
    ids.forEach( id => initialiseMapFromStore( id, $content.find( `.datamap-container#datamap-${id}` ) ) );
} );


module.exports = initialiseMapFromStore;