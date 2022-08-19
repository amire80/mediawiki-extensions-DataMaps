class DismissableMarkerEntry {
    constructor( panel, markerType, group, apiInstance, leafletMarker ) {
        this.panel = panel;
        this.apiInstance = apiInstance;

        const pair = this.panel.legend.createCheckboxField( this.panel.$root, '...', leafletMarker.options.dismissed,
            _ => this.panel.map.toggleMarkerDismissal( markerType, apiInstance, leafletMarker ) );
        this.field = pair[1];
        this.checkbox = pair[0];

        this.$label = this.field.$label;
        this.$label.empty();
        $( '<b>' ).text( this.panel.map.getCoordLabel( apiInstance[0], apiInstance[1] ) ).appendTo( this.$label );
        if ( apiInstance[2] && apiInstance[2].label ) {
            $( '<span>' ).text( apiInstance[2].label ).appendTo( this.$label );
        }

        // Add an icon
        if ( group.legendIcon ) {
            this.$icon = $( '<img width=24 height=24/>' ).attr( 'src', group.legendIcon ).prependTo( this.field.$header );
        } else if ( group.fillColor ) {
            this.$icon = $( '<div class="datamap-legend-circle">' ).css( {
                width: group.size+4,
                height: group.size+4,
                backgroundColor: group.fillColor,
                borderColor: group.strokeColor || group.fillColor,
                borderWidth: group.strokeWidth || 1,
            } ).prependTo( this.field.$header );
        }
    }
}


class DismissableMarkersLegend {
    constructor( legend ) {
        this.legend = legend;
        this.map = this.legend.map;
        // Root DOM element
        this.$root = this.legend.addTab( mw.msg( 'datamap-legend-tab-checklist' ) ).$element;
        //
        this.markers = [];

        // Register event handlers
        this.map.on( 'markerDismissChange', this.onDismissalChange, this );
        this.map.on( 'markerDismissChange', this.updateGroupBadges, this );
        this.map.on( 'markerReady', this.pushMarker, this );
        this.map.on( 'streamingDone', this.updateGroupBadges, this );

        // Prepare the panel
        this._initialisePanel();

        // Import existing markers if any have been loaded
        for ( const groupName in this.map.config.groups ) {
            const group = this.map.config.groups[groupName];
            if ( group.canDismiss ) {
                for ( const leafletMarker of ( this.map.layerManager.byLayer[groupName] || [] ) ) {
                    this.pushMarker( leafletMarker.attachedLayers.join( ' ' ), group, leafletMarker.apiInstance, leafletMarker );
                }
            }
        }

        // Call updaters now to bring the panel in sync
        this.updateGroupBadges();
    }


    _initialisePanel() {}


    pushMarker( markerType, group, instance, leafletMarker ) {
        if ( group.canDismiss ) {
            this.markers.push( new DismissableMarkerEntry( this, markerType, group, instance, leafletMarker ) );
        }
    }


    onDismissalChange() {

    }


    updateGroupBadges() {
        for ( const groupId in this.map.config.groups ) {
            const group = this.map.config.groups[groupId];
            if ( group.canDismiss && this.map.markerLegend.groupToggles[groupId] ) {
                const markers = this.map.layerManager.byLayer[groupId];
                const count = markers.filter( x => x.options.dismissed ).length;
                this.map.markerLegend.groupToggles[groupId].setBadge( `${count} / ${markers.length}` );
            }
        }
    }
};


module.exports = DismissableMarkersLegend;