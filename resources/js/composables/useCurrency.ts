/**
 * Currency utilities for consistent pricing display and input handling
 *
 * This composable handles the conversion between:
 * - Cents (integer, used internally and in API) - e.g., 190000 = $1.90
 * - Dollars (number, used for display/input) - e.g., 1.90
 *
 * **Backend Compatibility Note**:
 * - PHP backend uses nanos: ₱1.00 = 1,000,000,000 nanos
 * - This composable uses cents: $1.00 = 100 cents
 * - Conversion between PHP and this composable: PHP_nanos / 10,000,000 = cents
 *
 * @example
 * // Display price from API (cents)
 * const displayPrice = useCurrency().fromCents(apiPriceCents)
 *
 * @example
 * // Get value from user input (dollars) and convert to cents
 * const priceInCents = useCurrency().toCents(userInputDollars)
 */
export type CurrencyFormattingOptions = {
    /**
     * Minimum decimal places to show
     * @default 2 for USD
     */
    minDecimals?: number;

    /**
     * Maximum decimal places to show
     * @default 2 for USD
     */
    maxDecimals?: number;

    /**
     * Currency symbol prefix (e.g., '$')
     * @default '$'
     */
    symbol?: string;

    /**
     * Show currency symbol in formatted output
     * @default true
     */
    showSymbol?: boolean;

    /**
     * Locale for number formatting (e.g., 'en-US')
     * @default 'en-US'
     */
    locale?: string;
};

/**
 * Cents-based currency utility composable
 *
 * This utility uses cents to match the frontend data format.
 * The backend uses nanos for high-precision calculations, but all
 * API responses use cents to keep the data format stable.
 */
export function useCurrency() {
    /**
     * Convert cents to formatted dollar string for display
     *
     * @param cents - Price in cents (integer)
     * @param options - Formatting options
     * @returns Formatted dollar string (e.g., '$1.90' or '$1.900')
     *
     * @example
     * useCurrency().fromCents(190000) // '$1.90'
     * useCurrency().fromCents(100) // '$1.00'
     * useCurrency().fromCents(12345) // '$123.45'
     */
    const fromCents = (cents: number | null | undefined, options: CurrencyFormattingOptions = {}): string => {
        if (cents === null || cents === undefined) {
            return '';
        }

        const {
            minDecimals = 2,
            maxDecimals = 2,
            symbol = '$',
            showSymbol = true,
            locale = 'en-US',
        } = options;

        const dollars = cents / 100;
        const numberFormat = new Intl.NumberFormat(locale, {
            minimumFractionDigits: minDecimals,
            maximumFractionDigits: maxDecimals,
        });

        const formatted = numberFormat.format(dollars);

        return showSymbol ? `${symbol}${formatted}` : formatted;
    };

    /**
     * Convert formatted dollar string to cents
     *
     * @param dollars - Formatted dollar string (e.g., '$1.90') or raw dollar number
     * @returns Price in cents (integer)
     *
     * @example
     * useCurrency().toCents('$1.90') // 190
     * useCurrency().toCents('1.90') // 190
     * useCurrency().toCents(1.90) // 190
     * useCurrency().toCents(1.00) // 100
     */
    const toCents = (dollars: string | number | null | undefined): number | null => {
        if (dollars === null || dollars === undefined) {
            return null;
        }

        let dollarValue: number;

        if (typeof dollars === 'number') {
            dollarValue = dollars;
        } else if (typeof dollars === 'string') {
            // Remove currency symbol and commas
            const cleaned = dollars.replace(/[$,]/g, '').trim();
            dollarValue = parseFloat(cleaned);
        } else {
            return null;
        }

        if (isNaN(dollarValue)) {
            return null;
        }

        return Math.round(dollarValue * 100);
    };

    /**
     * Convert cents to dollar number (for internal calculations)
     *
     * @param cents - Price in cents
     * @returns Price in dollars (float)
     *
     * @example
     * useCurrency().centsToDollars(190000) // 1.90
     */
    const centsToDollars = (cents: number): number => {
        return cents / 100;
    };

    /**
     * Convert dollar number to cents (for API submission)
     *
     * @param dollars - Price in dollars
     * @returns Price in cents (integer)
     *
     * @example
     * useCurrency().dollarsToCents(1.90) // 190000
     */
    const dollarsToCents = (dollars: number): number => {
        return Math.round(dollars * 100);
    };

    /**
     * Format price with suffix (e.g., '/hour')
     *
     * @param cents - Price in cents
     * @param suffix - Text to append
     * @param options - Additional formatting options
     * @returns Formatted string with suffix
     *
     * @example
     * useCurrency().formatWithSuffix(190000, '/hour') // '$1.90/hour'
     * useCurrency().formatWithSuffix(100, '/hour') // '$1.00/hour'
     */
    const formatWithSuffix = (
        cents: number | null | undefined,
        suffix: string,
        options: CurrencyFormattingOptions = {},
    ): string => {
        if (cents === null || cents === undefined) {
            return suffix;
        }

        const formatted = fromCents(cents, options);

        return `${formatted}${suffix}`;
    };

    /**
     * Convert PHP nanos to cents (for backend compatibility)
     *
     * Backend uses nanos for high-precision: ₱1.00 = 1,000,000,000 nanos
     * Frontend uses cents: $1.00 = 100 cents
     *
     * @param nanos - Price in nanos from backend
     * @returns Price in cents
     *
     * @example
     * useCurrency().fromPHPNanos(1_000_000_000) // 100
     */
    const fromPHPNanos = (nanos: number): number => {
        return Math.floor(nanos / 10_000_000);
    };

    /**
     * Convert cents to PHP nanos (for backend compatibility)
     *
     * @param cents - Price in cents
     * @returns Price in nanos
     *
     * @example
     * useCurrency().toPHPNanos(100) // 1_000_000_000
     */
    const toPHPNanos = (cents: number): number => {
        return Math.floor(cents * 10_000_000);
    };

    return {
        fromCents,
        toCents,
        centsToDollars,
        dollarsToCents,
        formatWithSuffix,
        fromPHPNanos,
        toPHPNanos,
    };
}

// Convenience function for one-off usage
export function formatPrice(cents: number | null | undefined, options?: CurrencyFormattingOptions): string {
    return useCurrency().fromCents(cents, options);
}

export function parsePrice(dollars: string | number | null | undefined): number | null {
    return useCurrency().toCents(dollars);
}
