/** @typedef {import( './editor.js' )} MapVisualEditor */


/**
 * @typedef {Object} AbstractField
 * @property {string} labelMsg
 * @property {string} [descMsg]
 * @property {string} property
 * @property {( value: any ) => any} [transform]
 */
/**
 * @typedef {Object} BoolFieldProps
 * @property {'checkbox'} type
 * @property {( value: boolean ) => boolean} [transform]
 * @property {boolean} default
 */
/**
 * @typedef {Object} ValueFieldProps
 * @property {'dropdown'} type
 * @property {[ label: string, value: any ][]} options
 * @property {number} default
 */
/**
 * @typedef {Object} TextFieldProps
 * @property {'longtext'|'text'} type
 * @property {boolean} [inline=false]
 * @property {string} [placeholder]
 * @property {boolean} [required=false]
 * @property {string} default
 */
/**
 * @typedef {AbstractField & ( BoolFieldProps|ValueFieldProps|TextFieldProps )} FieldDescription
 */
/**
 * @typedef {Object} BuiltFieldProps
 * @property {OO.ui.InputWidget} widget
 */
/**
 * @typedef {FieldDescription & BuiltFieldProps} BuiltField
 */
/** @typedef {( data: import( './dataCapsule' ).Schema_DataMap ) => Record<string, any>} RootObjectGetter */
/**
 * @typedef {Object} DataEditorUiBuilderOptions
 * @property {RootObjectGetter} rootGetter
 * @property {FieldDescription[]} fields
 */


class DataEditorUiBuilder {
    /**
     * @param {MapVisualEditor} editor
     * @param {string} messageKey
     * @param {DataEditorUiBuilderOptions} options
     */
    constructor( editor, messageKey, options ) {
        /**
         * @private
         * @type {MapVisualEditor}
         */
        this._editor = editor;
        /**
         * @private
         * @type {OO.ui.FieldsetLayout}
         */
        this._fieldset = new OO.ui.FieldsetLayout();
        /** @type {HTMLElement} */
        this.element = this._fieldset.$element[ 0 ];
        /** @type {string} */
        this.messageKey = messageKey;
        /** @type {RootObjectGetter} */
        this._getRootInternal = options.rootGetter;
        /** @type {BuiltField[]} */
        this._builtFields = [];
        /** @type {boolean} */
        this._isLocked = false;

        this.addFields( options.fields );

        this._editor.on( 'sourceData', this._restoreValues, this );
    }


    /**
     * @param {string} key
     * @param {...string} parameters
     * @return {string}
     */
    msg( key, ...parameters ) {
        // eslint-disable-next-line mediawiki/msg-doc
        return mw.msg( `${this.messageKey}-${key}`, ...parameters );
    }


    /**
     * @param {boolean} value
     * @return {this}
     */
    setLock( value ) {
        for ( const field of this._builtFields ) {
            field.widget.setDisabled( value );
        }
        return this;
    }


    /**
     * @param {FieldDescription[]} fields
     */
    addFields( fields ) {
        if ( this._isLocked ) {
            throw new Error( 'Cannot add new fields after source data has been bound.' );
        }

        for ( const field of fields ) {
            let /** @type {OO.ui.InputWidget?} */ inputWidget;
            const isInline = 'inline' in field && field.inline;

            switch ( field.type ) {
                case 'text':
                    inputWidget = new OO.ui.TextInputWidget( {
                        required: field.required,
                        spellcheck: true
                    } );
                    break;
                case 'longtext':
                    inputWidget = new OO.ui.MultilineTextInputWidget( {
                        required: field.required,
                        spellcheck: true
                    } );
                    break;
                case 'checkbox':
                    inputWidget = new OO.ui.CheckboxInputWidget();
                    break;
                case 'dropdown':
                    inputWidget = new OO.ui.DropdownInputWidget( {
                        options: field.options.map( item => /** @type {OO.ui.DropdownInputWidget.Option} */ ( {
                            data: item[ 1 ],
                            // eslint-disable-next-line mediawiki/msg-doc
                            label: this.msg( `${field.labelMsg}-${item[ 0 ]}` )
                        } ) )
                    } );
                    field.default = field.options[ field.default ][ 1 ];
                    break;
                default:
                    throw new Error( 'Attempted to create field UI for an unknown type.' );
            }

            inputWidget.setDisabled( true );
            this._setInputWidgetValue( inputWidget, field.default );
            inputWidget.on( 'change', value => {
                if ( typeof value === 'string' && field.type === 'dropdown' ) {
                    // OOUI turns values into strings...
                    const normalised = field.options.find( x => `${x[ 1 ]}` === value );
                    if ( normalised ) {
                        value = normalised[ 1 ];
                    }
                }
                this._setProperty( field, value );
            } );

            this._fieldset.addItems( [
                new OO.ui.FieldLayout( inputWidget, {
                    label: isInline ? undefined : this.msg( field.labelMsg ),
                    help: field.descMsg ? this.msg( field.descMsg ) : undefined,
                    helpInline: true,
                    align: field.type === 'dropdown' ? 'top' : 'inline'
                } )
            ] );

            if ( isInline || ( 'placeholder' in field && field.placeholder ) ) {
                inputWidget.$input.attr( 'placeholder', field.placeholder || this.msg( field.labelMsg ) );
            }

            this._builtFields.push( Object.assign( {
                widget: inputWidget
            }, field ) );
        }
    }


    _getRoot() {
        return this._getRootInternal( this._editor.dataCapsule.get() );
    }


    /**
     * @param {FieldDescription} field
     * @param {any} value
     */
    _setProperty( field, value ) {
        if ( field.transform ) {
            value = field.transform( value );
        }
        if ( field.default === value ) {
            delete this._getRoot()[ field.property ];
        } else {
            this._getRoot()[ field.property ] = value;
        }
    }


    /**
     * @param {OO.ui.InputWidget} widget
     * @param {any} value
     */
    _setInputWidgetValue( widget, value ) {
        if ( widget instanceof OO.ui.CheckboxInputWidget ) {
            widget.setSelected( value );
        } else {
            widget.setValue( value );
        }
    }


    _restoreValues() {
        this._isLocked = true;
        const root = this._getRoot();
        for ( const field of this._builtFields ) {
            this._setInputWidgetValue( field.widget, root[ field.property ] );
        }
    }
}


/**
 * @constant
 * @type {( value: boolean ) => boolean}
 */
DataEditorUiBuilder.INVERT_BOOL = value => !value;


module.exports = DataEditorUiBuilder;