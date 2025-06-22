import { ImportFailure } from '@/types/index';

class FieldMappingService {
    private static fieldPatterns = {
        amount: /^(amount|betrag|sum|total|wert|saldo|castka|hodnota)$/i,
        partner: /^(partner|empf[aä]nger|sender|name|company|auftraggeber|merchant|counterparty|recipient|payee)$/i,
        booked_date: /^(booked.*date|date|datum|booking|gebucht|valuta|buchungstag|transaction.*date|posting.*date|value.*date)$/i,
        processed_date: /^(processed.*date|process.*date|settlement.*date|cleared.*date|datum.*zpracovani)$/i,
        description: /^(description|verwendung|zweck|memo|reference|beschreibung|details|note|text|popis|poznamka)$/i,
        target_iban: /^(target.*iban|empf[aä]nger.*iban|ziel.*iban|destination.*iban|recipient.*iban|to.*iban)$/i,
        source_iban: /^(source.*iban|sender.*iban|auftraggeber.*iban|from.*iban|origin.*iban)$/i,
        currency: /^(currency|w[aä]hrung|curr|mena|valuta)$/i,
        transaction_id: /^(transaction.*id|trans.*id|id|referenz|reference|ref|cislo.*transakce)$/i,
        type: /^(type|typ|kategorie|druh|transaction.*type)$/i,
        note: /^(note|notes|poznamka|poznamky|additional.*info|extra.*info)$/i,
        recipient_note: /^(recipient.*note|recipient.*memo|for.*recipient|pro.*prijemce)$/i,
        place: /^(place|location|misto|lokalita|where|venue)$/i,
        balance_after_transaction: /^(balance.*after|remaining.*balance|new.*balance|balance|zůstatek|zustatek)$/i,
        merchant_id: /^(merchant|obchodnik|store|shop|vendor)$/i,
        category_id: /^(category|kategorie|group|skupina|class|trida)$/i,
        account_id: /^(account|ucet|konto|account.*number)$/i,
    };

    static mapFields(failure: ImportFailure, importData: any, fieldDefinitions?: any): any {
        const { raw_data, metadata, parsed_data } = failure;
        const headers = metadata.headers || [];

        const mappings = new Map();

        headers.forEach((header, index) => {
            const value = raw_data[index];
            if (!value && value !== 0) return;

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
                    }
                }
            });
        });

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
                }
                // Then try auto-mapping
                else if (mappings.has(fieldName)) {
                    value = mappings.get(fieldName).suggestedValue;
                }
                // Finally use smart defaults
                else {
                    value = this.getSmartDefault(fieldName, fieldDef, failure, importData);
                }

                defaultValues[fieldName] = value;
            });
        } else {
            // Legacy fallback
            defaultValues.transaction_id = parsed_data?.transaction_id || mappings.get('transaction_id')?.suggestedValue || `TRX-${Date.now()}`;
            defaultValues.amount = Math.abs(parsed_data?.amount || mappings.get('amount')?.suggestedValue || 0);
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

        return defaultValues;
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
                return isNaN(numericValue) ? 0 : Math.abs(numericValue);
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
