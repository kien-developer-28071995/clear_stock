/**
 * Polaris `<s-option value="">` falls back to its label as the value (like a native
 * <option> without a value), so a "none / all" choice would submit its label text.
 * Such options use NO_VALUE instead; convert with these helpers at the select.
 */
export const NO_VALUE = '__none__';

/** Value for `<s-select value>`: empty or missing selects the NO_VALUE option. */
export function optionValue(value: string | number | null | undefined): string {
    return value === null || value === undefined || value === '' ? NO_VALUE : String(value);
}

/** Value read from a select's change event: the NO_VALUE option means ''. */
export function fromOption(value: string): string {
    return value === NO_VALUE ? '' : value;
}

/**
 * `key` for an `<s-select>` whose options come from the API. The component shows the option
 * matching its value only among the options it had when the value was set: with options that
 * arrive later it keeps showing the first one ("No supplier" for a product that has one).
 * A new key when the options change builds it again with value and options together.
 */
export function optionsKey(count: number): string {
    return `options-${count}`;
}
