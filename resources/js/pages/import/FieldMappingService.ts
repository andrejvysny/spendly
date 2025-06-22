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
                if (pattern.test(header)) {
                    const confidence = this.calculateConfidence(header, fieldName, value);
                    if (!mappings.has(fieldName) || mappings.get(fieldName).confidence < confidence) {
                        mappings.set(fieldName, {
                            confidence,
                            suggestedValue: this.transformValue(fieldName, value, fieldDefinitions),
                            originalValue: value,
                            fieldName: header,
                        });
                        
                        actuallyMappedFields.add(fieldName); // Mark as actually mapped
                        console.log(`✅ Mapped "${header}" (${value}) -> ${fieldName} (confidence: ${confidence})`);
                    }
                }
            });
        });

        console.log('📋 Final mappings:', Array.from(mappings.entries()));
        console.log('🎯 Actually mapped fields:', Array.from(actuallyMappedFields));

        // Create default values based on field definitions or fallback to legacy structure
        const defaultValues: any = {};

        if (fieldDefinitions) {
            // Use dynamic field definitions
            fieldDefinitions.field_order.forEach((fieldName: string) => {
                const fieldDef = fieldDefinitions.fields[fieldName];
                if (!fieldDef) return;

                let value: any = null;

                // Try to get value from parsed data first
                if (parsed_data && parsed_data[fieldName] !== undefined) {
                    value = parsed_data[fieldName];
                    console.log(`📊 Using parsed data for ${fieldName}:`, value);
                }
                // Then try auto-mapping
                else if (mappings.has(fieldName)) {
                    value = mappings.get(fieldName).suggestedValue;
                    console.log(`🎯 Using auto-mapped value for ${fieldName}:`, value);
                }
                // Finally use smart defaults
                else {
                    value = this.getSmartDefault(fieldName, fieldDef, failure, importData);
                    console.log(`🔧 Using smart default for ${fieldName}:`, value);
                }

                defaultValues[fieldName] = value;
            });
        } else {
            console.log('⚠️ No field definitions available, using legacy fallback');
            // Legacy fallback
            defaultValues.transaction_id = parsed_data?.transaction_id || mappings.get('transaction_id')?.suggestedValue || `TRX-${Date.now()}`;
            defaultValues.amount = parsed_data?.amount || mappings.get('amount')?.suggestedValue || 0; // Don't force absolute value here
            defaultValues.currency = parsed_data?.currency || mappings.get('currency')?.suggestedValue || importData?.currency || 'EUR';
            defaultValues.booked_date = parsed_data?.booked_date || mappings.get('booked_date')?.suggestedValue || new Date().toISOString().split('T')[0];
            defaultValues.processed_date = parsed_data?.processed_date || mappings.get('processed_date')?.suggestedValue || new Date().toISOString().split('T')[0];
            defaultValues.description = parsed_data?.description || mappings.get('description')?.suggestedValue || failure.error_message;
            defaultValues.target_iban = parsed_data?.target_iban || mappings.get('target_iban')?.suggestedValue || null;
            defaultValues.source_iban = parsed_data?.source_iban || mappings.get('source_iban')?.suggestedValue || null;
            defaultValues.partner = parsed_data?.partner || mappings.get('partner')?.suggestedValue || '';
            defaultValues.type = 'PAYMENT';
            defaultValues.account_id = parsed_data?.account_id || 1;
        }

        console.log('🎉 Final mapped values:', defaultValues);
        
        // Return both the values and the tracking of what was actually mapped
        return {
            values: defaultValues,
            actuallyMappedFields: actuallyMappedFields
        };
    }

    private static getSmartDefault(fieldName: string, fieldDef: any, failure: ImportFailure, importData: any): any {
        switch (fieldName) {
            case 'transaction_id':
                return `TRX-${Date.now()}`;
            case 'currency':
                return importData?.currency || 'EUR';
            case 'booked_date':
            case 'processed_date':
                return new Date().toISOString().split('T')[0];
            case 'description':
                return failure.error_message || 'Import failure transaction';
            case 'type':
                return 'PAYMENT';
            case 'amount':
                return 0;
            case 'account_id':
                // Try to get first available account from field definitions options
                if (fieldDef.options && fieldDef.options.length > 0) {
                    return fieldDef.options[0].value;
                }
                return null;
            default:
                if (fieldDef.type === 'number') return 0;
                if (fieldDef.type === 'select' && fieldDef.options && fieldDef.options.length > 0) {
                    return fieldDef.required ? fieldDef.options[0].value : null;
                }
                return fieldDef.required ? '' : null;
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

        headers.forEach((header) => {
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
