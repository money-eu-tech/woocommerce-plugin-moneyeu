( function() {
    'use strict';

    var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
    var createElement = wp.element.createElement;
    var useEffect = wp.element.useEffect;
    var decodeEntities = wp.htmlEntities.decodeEntities;

    var settings = wc.wcSettings.getSetting( 'moneyeu_payments_data', {} );
    var title = decodeEntities( settings.title || 'Credit Card (MoneyEU)' );
    var description = decodeEntities( settings.description || '' );
    var isHpp = settings.flowType === 'hpp';

    var cardData = {
        number: '',
        expMonth: '',
        expYear: '',
        cvv: ''
    };

    function renderField( id, label, control, extraClass ) {
        return createElement(
            'div',
            { className: 'moneyeu2-card-field' + ( extraClass ? ' ' + extraClass : '' ) },
            createElement(
                'label',
                { htmlFor: id },
                createElement( 'span', null, label ),
                createElement( 'span', { className: 'moneyeu2-card-field__required' }, 'Required' )
            ),
            control
        );
    }

    var MoneyEUContent = function( props ) {
        var eventRegistration = props.eventRegistration;
        var emitResponse = props.emitResponse;
        var onPaymentSetup = eventRegistration.onPaymentSetup;

        useEffect( function() {
            var unsubscribe = onPaymentSetup( function() {
                if ( isHpp ) {
                    return { type: emitResponse.responseTypes.SUCCESS };
                }

                var cardNumber = cardData.number.replace( /\s+/g, '' );
                var expMonth = cardData.expMonth;
                var expYear = cardData.expYear;
                var cvv = cardData.cvv;

                if ( ! cardNumber || ! /^\d{12,19}$/.test( cardNumber ) ) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Please enter a valid card number.'
                    };
                }
                if ( ! expMonth ) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Please select an expiry month.'
                    };
                }
                if ( ! expYear ) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Please select an expiry year.'
                    };
                }
                if ( ! cvv || ! /^\d{3,4}$/.test( cvv ) ) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Please enter a valid CVV.'
                    };
                }

                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            moneyeu2_card_number: cardNumber,
                            moneyeu2_card_exp_month: expMonth,
                            moneyeu2_card_exp_year: expYear,
                            moneyeu2_card_cvv: cvv
                        }
                    }
                };
            } );

            return unsubscribe;
        }, [ onPaymentSetup, emitResponse.responseTypes ] );

        var descriptionEl = description
            ? createElement( 'div', { className: 'moneyeu2-card-fields__description' }, createElement( 'p', null, description ) )
            : null;

        if ( isHpp ) {
            return createElement(
                'div',
                { className: 'moneyeu2-card-fields moneyeu2-card-fields--redirect' },
                descriptionEl,
                createElement(
                    'p',
                    null,
                    'You\'ll be redirected to a secure MoneyEU page to enter your payment details and complete your purchase.'
                )
            );
        }

        var currentYear = new Date().getFullYear();
        var monthOptions = [ createElement( 'option', { key: '', value: '' }, 'MM' ) ];
        for ( var m = 1; m <= 12; m++ ) {
            var monthValue = ( m < 10 ? '0' : '' ) + m;
            monthOptions.push( createElement( 'option', { key: monthValue, value: monthValue }, monthValue ) );
        }

        var yearOptions = [ createElement( 'option', { key: '', value: '' }, 'YYYY' ) ];
        for ( var y = currentYear; y <= currentYear + 15; y++ ) {
            yearOptions.push( createElement( 'option', { key: y, value: String( y ) }, String( y ) ) );
        }

        return createElement(
            'div',
            { className: 'moneyeu2-card-fields moneyeu2-card-fields--blocks' },
            descriptionEl,
            createElement(
                'div',
                { className: 'moneyeu2-card-fields__body' },
                renderField(
                    'moneyeu2-blocks-card-number',
                    'Card Number',
                    createElement( 'input', {
                        className: 'moneyeu2-input',
                        id: 'moneyeu2-blocks-card-number',
                        type: 'text',
                        inputMode: 'numeric',
                        autoComplete: 'cc-number',
                        placeholder: '1234 5678 9012 3456',
                        maxLength: 19,
                        onChange: function( e ) { cardData.number = e.target.value; }
                    } )
                ),
                createElement(
                    'div',
                    { className: 'moneyeu2-card-fields__grid' },
                    renderField(
                        'moneyeu2-blocks-card-expiry-month',
                        'Expiry Month',
                        createElement( 'select', {
                            className: 'moneyeu2-input',
                            id: 'moneyeu2-blocks-card-expiry-month',
                            autoComplete: 'cc-exp-month',
                            onChange: function( e ) { cardData.expMonth = e.target.value; }
                        }, monthOptions )
                    ),
                    renderField(
                        'moneyeu2-blocks-card-expiry-year',
                        'Expiry Year',
                        createElement( 'select', {
                            className: 'moneyeu2-input',
                            id: 'moneyeu2-blocks-card-expiry-year',
                            autoComplete: 'cc-exp-year',
                            onChange: function( e ) { cardData.expYear = e.target.value; }
                        }, yearOptions )
                    ),
                    renderField(
                        'moneyeu2-blocks-card-cvv',
                        'CVV',
                        createElement( 'input', {
                            className: 'moneyeu2-input',
                            id: 'moneyeu2-blocks-card-cvv',
                            type: 'text',
                            inputMode: 'numeric',
                            autoComplete: 'cc-csc',
                            placeholder: '123',
                            maxLength: 4,
                            onChange: function( e ) { cardData.cvv = e.target.value; }
                        } ),
                        'moneyeu2-card-field--cvv'
                    )
                )
            )
        );
    };

    registerPaymentMethod( {
        name: 'moneyeu_payments',
        label: createElement( 'span', null, title ),
        content: createElement( MoneyEUContent ),
        edit: createElement( MoneyEUContent ),
        canMakePayment: function() { return true; },
        ariaLabel: title,
        supports: {
            features: settings.supports || [ 'products' ]
        }
    } );
} )();
