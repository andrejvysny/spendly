import { ImportFailure } from '@/types/index';

class FieldMappingService {
    private static fieldPatterns = {
        amount: /^(amount|betrag|sum|total|wert|saldo|castka|hodnota|suma|kwota|montant|valor|importo|menge|price|cena)$/i,
        partner: /^(partner|empf[aä]nger|sender|name|company|auftraggeber|merchant|counterparty|recipient|payee|beneficiary|payable|vendor|supplier|klient|cliente|fournisseur|fornitore)$/i,
        booked_date: /^(booked.*date|date|datum|booking|gebucht|valuta|buchungstag|transaction.*date|posting.*date|value.*date|effective.*date|settlement.*date|execution.*date|fecha|data|datum.*transakce)$/i,
        processed_date: /^(processed.*date|process.*date|settlement.*date|cleared.*date|datum.*zpracovani|processing.*date|execution.*date|completion.*date)$/i,
        description: /^(description|verwendung|zweck|memo|reference|beschreibung|details|note|text|popis|poznamka|descripcion|descrizione|concept|libelle|motivo|raison)$/i,
        target_iban: /^(target.*iban|empf[aä]nger.*iban|ziel.*iban|destination.*iban|recipient.*iban|to.*iban|beneficiary.*iban|payee.*iban|credit.*iban)$/i,
        source_iban: /^(source.*iban|sender.*iban|auftraggeber.*iban|from.*iban|origin.*iban|debtor.*iban|payer.*iban|debit.*iban)$/i,
        currency: /^(currency|w[aä]hrung|curr|mena|valuta|devise|moneda|divisa|waluta|ccur|ccy)$/i,
        transaction_id: /^(transaction.*id|trans.*id|id|referenz|reference|ref|cislo.*transakce|numero|numero.*transaccion|transaction.*ref|trans.*ref|txn.*id|operation.*id)$/i,
        type: /^(type|typ|kategorie|druh|transaction.*type|operation.*type|payment.*type|categoria|kategoria|genre|tipo)$/i,
        note: /^(note|notes|poznamka|poznamky|additional.*info|extra.*info|commentary|comment|observacion|osservazione|remarque|bemerkung)$/i,
        recipient_note: /^(recipient.*note|recipient.*memo|for.*recipient|pro.*prijemce|beneficiary.*note|payee.*note|destination.*note)$/i,
        place: /^(place|location|misto|lokalita|where|venue|lieu|lugar|posto|ort|lokalizacja)$/i,
        balance_after_transaction: /^(balance.*after|remaining.*balance|new.*balance|balance|zůstatek|zustatek|saldo.*final|balance.*final|solde)$/i,
        merchant_id: /^(merchant|obchodnik|store|shop|vendor|comerciante|commerciante|magasin|negozio|laden|sklep)$/i,
        category_id: /^(category|kategorie|group|skupina|class|trida|categoria|classe|groupe|klasse|categoria)$/i,
        account_id: /^(account|ucet|konto|account.*number|numero.*cuenta|numero.*conto|numero.*compte|kontonummer|numer.*konta)$/i,
    };

    static mapFields(failure: ImportFailure, importData: any, fieldDefinitions?: any): any {
        const { raw_data, metadata, parsed_data } = failure;
        const headers = metadata.headers || [];

        console.log('🔍 FieldMappingService.mapFields called with:', {
            failureId: failure.id,
            headers,
            raw_data,
            parsed_data,
            fieldDefinitions: fieldDefinitions ? 'Available' : 'Not available'
        });

        const mappings = new Map();
        const actuallyMappedFields = new Set<string>(); // Track fields that were actually mapped from raw data

        headers.forEach((header, index) => {
            const value = raw_data[index];

            // Enhanced empty checking: exclude null, undefined, empty strings, and whitespace-only strings
            const isValueEmpty = value === null ||
                                value === undefined ||
                                value === '' ||
                                (typeof value === 'string' && value.trim() === '') ||
                                (typeof value === 'number' && isNaN(value));

            if (isValueEmpty && value !== 0) {
                console.log(`❌ Skipping empty value for header "${header}":`, value);
                return;
            }

            Object.entries(this.fieldPatterns).forEach(([fieldName, pattern]) => {
                // NEVER auto-map account_id from CSV data - account comes from import context
                if (fieldName === 'account_id') {
                    console.log(`❌ Skipping auto-mapping for account_id - should come from import metadata`);
                    return;
                }

                if (pattern.test(header)) {
                    const confidence = this.calculateConfidence(header, fieldName, value);
                    if (!mappings.has(fieldName) || mappings.get(fieldName).confidence < confidence) {
                        const transformedValue = this.transformValue(fieldName, value, fieldDefinitions);

                        // Double-check that the transformed value is not empty/invalid
                        const isTransformedValueEmpty = transformedValue === null ||
                                                      transformedValue === undefined ||
                                                      transformedValue === '' ||
                                                      (typeof transformedValue === 'string' && transformedValue.trim() === '') ||
                                                      (typeof transformedValue === 'number' && isNaN(transformedValue));

                        if (isTransformedValueEmpty && transformedValue !== 0) {
                            console.log(`❌ Skipping mapping "${header}" -> ${fieldName} because transformed value is empty:`, transformedValue);
                            return;
                        }

                        mappings.set(fieldName, {
                            confidence,
                            suggestedValue: transformedValue,
                            originalValue: value,
                            fieldName: header,
                        });

                        actuallyMappedFields.add(fieldName); // Mark as actually mapped
                        console.log(`✅ Mapped "${header}" (${value}) -> ${fieldName} (confidence: ${confidence}, transformed: ${transformedValue})`);
                    }
                }
            });
        });

        console.log('📋 Final mappings:', Array.from(mappings.entries()));
        console.log('🎯 Actually mapped fields from CSV:', Array.from(actuallyMappedFields));

        // Create default values based on field definitions or fallback to legacy structure
        const defaultValues: any = {};
        const finalActuallyMappedFields = new Set<string>(); // Final set of fields that were actually mapped from CSV

        if (fieldDefinitions) {
            // Use dynamic field definitions
            fieldDefinitions.field_order.forEach((fieldName: string) => {
                const fieldDef = fieldDefinitions.fields[fieldName];
                if (!fieldDef) return;

                let value: any = null;
                let wasActuallyMapped = false;

                // Try to get value from parsed data first
                if (parsed_data && parsed_data[fieldName] !== undefined) {
                    value = parsed_data[fieldName];
                    console.log(`📊 Using parsed data for ${fieldName}:`, value);
                }
                // Then try auto-mapping (only if we have a valid mapping from CSV)
                else if (mappings.has(fieldName) && actuallyMappedFields.has(fieldName)) {
                    value = mappings.get(fieldName).suggestedValue;
                    wasActuallyMapped = true;
                    finalActuallyMappedFields.add(fieldName);
                    console.log(`🎯 Using auto-mapped value for ${fieldName}:`, value);
                }
                // Finally use smart defaults
                else {
                    value = this.getSmartDefault(fieldName, fieldDef, failure, importData);
                    console.log(`🔧 Using smart default for ${fieldName}:`, value);

                    // Additional validation for select fields to prevent empty strings
                    if (fieldDef?.type === 'select' && value === '') {
                        console.log(`⚠️ Smart default returned empty string for select field ${fieldName}, fixing...`);
                        if (fieldDef.options && fieldDef.options.length > 0) {
                            value = fieldDef.options[0].value.toString();
                            console.log(`🔧 Fixed select field ${fieldName} with first option:`, value);
                        } else {
                            value = null;
                            console.log(`🔧 No options available for select field ${fieldName}, setting to null`);
                        }
                    }
                }

                // Additional logging for problematic fields
                if (fieldName === 'account_id' || fieldName === 'currency') {
                    console.log(`🔍 Final value for ${fieldName}:`, {
                        value,
                        valueType: typeof value,
                        fieldDef: fieldDef,
                        isRequired: fieldDef?.required,
                        hasOptions: fieldDef?.options?.length > 0,
                        firstOption: fieldDef?.options?.[0],
                        wasActuallyMapped,
                        importData: {
                            currency: importData?.currency,
                            accountId: importData?.metadata?.account_id
                        }
                    });
                }

                // Final validation to ensure fields have proper values
                if (fieldDef?.type === 'select') {
                    // Only apply emergency fixes if value is truly empty
                    const isEmptyValue = value === null || value === undefined || value === '' || 
                                       (typeof value === 'string' && value.trim() === '');
                    
                    if (isEmptyValue) {
                        // Special handling for specific fields
                        if (fieldName === 'currency') {
                            value = importData?.currency || 'EUR';
                            console.log(`🚨 Emergency fix: Set empty currency to:`, value);
                        } else if (fieldName === 'account_id' && importData?.metadata?.account_id) {
                            value = importData.metadata.account_id.toString();
                            console.log(`🚨 Emergency fix: Set empty account_id from import metadata:`, value);
                        } else if (fieldDef.required && fieldDef.options && fieldDef.options.length > 0) {
                            value = fieldDef.options[0].value.toString();
                            console.log(`🚨 Emergency fix: Set empty required select field ${fieldName} to first option:`, value);
                        } else if (!fieldDef.required) {
                            // For optional fields, leave as null to show "-- None --"
                            value = null;
                            console.log(`✅ Optional select field ${fieldName} left as null (no selection)`);
                        } else {
                            // This shouldn't happen - select without options
                            console.error(`❌ Select field ${fieldName} has no options and no value!`);
                            value = null;
                        }
                    }
                }

                defaultValues[fieldName] = value;
            });
        } else {
            console.log('⚠️ No field definitions available, using legacy fallback');
            // Legacy fallback - only mark fields as mapped if they actually came from CSV
            const legacyMappings = {
                transaction_id: parsed_data?.transaction_id || (mappings.has('transaction_id') && actuallyMappedFields.has('transaction_id') ? mappings.get('transaction_id')?.suggestedValue : null) || `TRX-${Date.now()}`,
                amount: parsed_data?.amount || (mappings.has('amount') && actuallyMappedFields.has('amount') ? mappings.get('amount')?.suggestedValue : null) || 0,
                currency: parsed_data?.currency || (mappings.has('currency') && actuallyMappedFields.has('currency') ? mappings.get('currency')?.suggestedValue : null) || importData?.currency || 'EUR',
                booked_date: parsed_data?.booked_date || (mappings.has('booked_date') && actuallyMappedFields.has('booked_date') ? mappings.get('booked_date')?.suggestedValue : null) || new Date().toISOString().split('T')[0],
                processed_date: parsed_data?.processed_date || (mappings.has('processed_date') && actuallyMappedFields.has('processed_date') ? mappings.get('processed_date')?.suggestedValue : null) || new Date().toISOString().split('T')[0],
                description: parsed_data?.description || (mappings.has('description') && actuallyMappedFields.has('description') ? mappings.get('description')?.suggestedValue : null) || failure.error_message,
                target_iban: parsed_data?.target_iban || (mappings.has('target_iban') && actuallyMappedFields.has('target_iban') ? mappings.get('target_iban')?.suggestedValue : null) || null,
                source_iban: parsed_data?.source_iban || (mappings.has('source_iban') && actuallyMappedFields.has('source_iban') ? mappings.get('source_iban')?.suggestedValue : null) || null,
                partner: parsed_data?.partner || (mappings.has('partner') && actuallyMappedFields.has('partner') ? mappings.get('partner')?.suggestedValue : null) || '',
                type: 'PAYMENT',
                account_id: parsed_data?.account_id || 1
            };

            // Track which fields were actually mapped in legacy mode
            Object.entries(legacyMappings).forEach(([fieldName, value]) => {
                if (mappings.has(fieldName) && actuallyMappedFields.has(fieldName)) {
                    const mappedValue = mappings.get(fieldName)?.suggestedValue;
                    if (value === mappedValue) {
                        finalActuallyMappedFields.add(fieldName);
                    }
                }

                // Final validation for legacy mode as well
                if (value === '' && fieldName === 'currency') {
                    value = importData?.currency || 'EUR';
                    console.log(`🚨 Legacy emergency fix: Set empty currency to:`, value);
                }
                if (value === '' && fieldName === 'account_id') {
                    value = importData?.metadata?.account_id?.toString() || '1';
                    console.log(`🚨 Legacy emergency fix: Set empty account_id to:`, value);
                }

                defaultValues[fieldName] = value;
            });
        }

        // Final cleanup - ensure proper values for all fields
        if (fieldDefinitions) {
            fieldDefinitions.field_order.forEach((fieldName: string) => {
                const value = defaultValues[fieldName];
                const fieldDef = fieldDefinitions.fields[fieldName];

                if (!fieldDef) return;

                // Check for problematic values
                const isEmpty = value === '' || value === null || value === undefined;

                if (isEmpty) {
                    console.log(`⚠️ Found empty/null value for field ${fieldName} (type: ${fieldDef.type}, required: ${fieldDef.required})`);

                    // Handle based on field type
                    switch (fieldDef.type) {
                        case 'select':
                            // For select fields, only set first option if required
                            if (fieldDef.required && fieldDef.options && fieldDef.options.length > 0) {
                                defaultValues[fieldName] = fieldDef.options[0].value.toString();
                                console.log(`🔧 Fixed empty required select field ${fieldName} to first option:`, defaultValues[fieldName]);
                            } else if (!fieldDef.required) {
                                // For optional fields, keep as null to show "-- None --"
                                defaultValues[fieldName] = null;
                                console.log(`✅ Optional select field ${fieldName} kept as null (no selection)`);
                            } else {
                                // This shouldn't happen - required select field without options
                                console.error(`❌ Required select field ${fieldName} has no options!`);
                                defaultValues[fieldName] = null;
                            }
                            break;
                        case 'text':
                        case 'textarea':
                            // For text fields, empty string is acceptable
                            if (value === null || value === undefined) {
                                defaultValues[fieldName] = '';
                                console.log(`🔧 Fixed null text field ${fieldName} to empty string`);
                            }
                            break;
                        case 'number':
                            // For number fields, 0 is acceptable
                            if (value === null || value === undefined || value === '') {
                                defaultValues[fieldName] = 0;
                                console.log(`🔧 Fixed empty number field ${fieldName} to 0`);
                            }
                            break;
                        case 'date':
                            // For date fields, provide today's date if required
                            if (fieldDef.required && (value === null || value === undefined || value === '')) {
                                defaultValues[fieldName] = new Date().toISOString().split('T')[0];
                                console.log(`🔧 Fixed empty date field ${fieldName} to today`);
                            }
                            break;
                    }
                }

                // Special handling for specific fields
                if (fieldName === 'currency' && isEmpty) {
                    defaultValues[fieldName] = importData?.currency || 'EUR';
                    console.log(`🔧 Special: Set currency to:`, defaultValues[fieldName]);
                }
                if (fieldName === 'account_id' && isEmpty) {
                    const accountId = importData?.metadata?.account_id?.toString() ||
                                    (fieldDef.options && fieldDef.options.length > 0 ? fieldDef.options[0].value.toString() : '1');
                    defaultValues[fieldName] = accountId;
                    console.log(`🔧 Special: Set account_id to:`, defaultValues[fieldName]);
                }
            });
        }

        console.log('🎉 Final mapped values:', defaultValues);
        console.log('🎉 Final actually mapped fields:', Array.from(finalActuallyMappedFields));

        // Return both the values and the tracking of what was actually mapped
        return {
            values: defaultValues,
            actuallyMappedFields: finalActuallyMappedFields
        };
    }

    private static getSmartDefault(fieldName: string, fieldDef: any, failure: ImportFailure, importData: any): any {
        switch (fieldName) {
            case 'transaction_id':
                return `TRX-${Date.now()}`;
            case 'currency':
                // For currency, prioritize importData currency, then first valid option from field definitions
                const importCurrency = importData?.currency;
                console.log(`🔍 Getting currency default - importCurrency: ${importCurrency}, fieldDef options:`, fieldDef?.options);

                if (importCurrency && fieldDef?.options?.some((opt: any) => opt.value.toString() === importCurrency.toString())) {
                    console.log(`✅ Using import currency: ${importCurrency}`);
                    return importCurrency.toString();
                }
                // Fallback to first valid option
                if (fieldDef?.options && fieldDef.options.length > 0) {
                    const firstOption = fieldDef.options[0].value.toString();
                    console.log(`✅ Using first currency option: ${firstOption}`);
                    return firstOption;
                }
                console.log(`⚠️ No currency options available, defaulting to EUR`);
                return 'EUR';
            case 'booked_date':
            case 'processed_date':
                return new Date().toISOString().split('T')[0];
            case 'description':
                return failure.error_message || 'Import failure transaction';
            case 'type':
                return 'PAYMENT';
            case 'amount':
                return 0;
            case 'partner':
                // Partner is a required text field, should never be null
                return '';
            case 'merchant_id':
            case 'category_id':
                // For optional select fields, return null so they show "-- None --"
                // Only return first option if field is required
                if (fieldDef?.required && fieldDef?.options && fieldDef.options.length > 0) {
                    return fieldDef.options[0].value.toString();
                }
                return null;
            case 'account_id':
                // NEVER auto-map account_id from CSV - always use the account from import metadata
                const accountFromImport = importData?.metadata?.account_id;
                console.log(`🔍 Getting account_id default - accountFromImport: ${accountFromImport}, fieldDef options:`, fieldDef?.options);

                if (accountFromImport) {
                    const accountIdStr = accountFromImport.toString();
                    console.log(`✅ Using account from import metadata: ${accountIdStr}`);
                    return accountIdStr;
                }
                // Fallback to first available account from field definitions options
                if (fieldDef?.options && fieldDef.options.length > 0) {
                    const firstOption = fieldDef.options[0].value.toString();
                    console.log(`✅ Using first account option: ${firstOption}`);
                    return firstOption;
                }
                console.log(`⚠️ No account options available, defaulting to '1'`);
                return fieldDef?.required ? '1' : null;
            default:
                if (fieldDef?.type === 'number') return 0;
                if (fieldDef?.type === 'select') {
                    // For select fields, only return first option if field is required
                    // Optional fields should return null to show "-- None --"
                    if (fieldDef?.required && fieldDef?.options && fieldDef.options.length > 0) {
                        return fieldDef.options[0].value.toString();
                    }
                    // For optional fields or no options, return null
                    return null;
                }
                // For text fields that are required, provide empty string as default
                if (fieldDef?.type === 'text' && fieldDef?.required) {
                    return '';
                }
                // For required fields (non-select), provide appropriate defaults
                if (fieldDef?.required && fieldDef?.type !== 'select') {
                    return '';
                }
                return null;
        }
    }

    private static calculateConfidence(header: string, fieldName: string, value: any): number {
        let confidence = 0.5;

        if (this.fieldPatterns[fieldName as keyof typeof this.fieldPatterns]?.test(header)) {
            confidence += 0.3;
        }

        // Value format validation
        if (fieldName === 'amount' && !isNaN(parseFloat(value))) confidence += 0.2;
        if ((fieldName === 'booked_date' || fieldName === 'processed_date') && this.isValidDate(value)) confidence += 0.2;
        if (fieldName === 'currency' && /^[A-Z]{3}$/.test(value)) confidence += 0.2;
        if (fieldName === 'transaction_id' && value.toString().length > 3) confidence += 0.1;

        return Math.min(confidence, 1.0);
    }

    private static transformValue(fieldName: string, value: any, fieldDefinitions?: any): any {
        switch (fieldName) {
            case 'amount': {
                const numericValue = parseFloat(
                    value
                        .toString()
                        .replace(/[^\d.,-]/g, '')
                        .replace(',', '.'),
                );
                return isNaN(numericValue) ? 0 : numericValue; // Preserve negative values for expenses
            }
            case 'booked_date':
            case 'processed_date':
                return this.parseDate(value);
            case 'currency':
                return value.toString().toUpperCase();
            case 'balance_after_transaction':
                const balanceValue = parseFloat(
                    value
                        .toString()
                        .replace(/[^\d.,-]/g, '')
                        .replace(',', '.')
                );
                return isNaN(balanceValue) ? null : balanceValue;
            case 'type':
                // Try to map common type values
                const typeValue = value.toString().toUpperCase();
                const typeMapping: Record<string, string> = {
                    'TRANSFER': 'TRANSFER',
                    'DEPOSIT': 'DEPOSIT',
                    'WITHDRAWAL': 'WITHDRAWAL',
                    'PAYMENT': 'PAYMENT',
                    'CARD': 'CARD_PAYMENT',
                    'CARD_PAYMENT': 'CARD_PAYMENT',
                    'EXCHANGE': 'EXCHANGE',
                };
                return typeMapping[typeValue] || 'PAYMENT';
            default:
                return value.toString().trim();
        }
    }

    private static isValidDate(dateString: any): boolean {
        const date = new Date(dateString);
        return date instanceof Date && !isNaN(date.getTime());
    }

    private static parseDate(dateString: any): string {
        const date = new Date(dateString);
        if (this.isValidDate(dateString)) {
            return date.toISOString().split('T')[0];
        }
        return new Date().toISOString().split('T')[0];
    }

    static getHighlightedFields(failure: ImportFailure): Set<string> {
        const highlighted = new Set<string>();
        const headers = failure.metadata.headers || [];
        const raw_data = failure.raw_data || [];

        headers.forEach((header, index) => {
            const value = raw_data[index];

            // Only highlight fields that have non-empty values
            const isValueEmpty = value === null ||
                                value === undefined ||
                                value === '' ||
                                (typeof value === 'string' && value.trim() === '') ||
                                (typeof value === 'number' && isNaN(value));

            if (isValueEmpty && value !== 0) {
                return; // Skip empty values
            }

            Object.values(this.fieldPatterns).forEach((pattern) => {
                if (pattern.test(header)) {
                    highlighted.add(header.toLowerCase());
                }
            });
        });

        return highlighted;
    }
}



export default FieldMappingService;
