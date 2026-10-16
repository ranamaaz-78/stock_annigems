/**
 * Pricing calculator — mirrors PricingCalculationService for live UI recalc.
 * Tax applies AFTER discount (order must match server).
 */

/**
 * @param {number|string} value
 * @returns {number}
 */
export function money(value) {
    const n = parseFloat(value);
    return Number.isFinite(n) ? Math.round(n * 100) / 100 : 0;
}

/**
 * @param {object} line
 * @param {Array<object>} stones
 * @returns {object}
 */
export function calculateLine(line, stones = []) {
    if (line.line_type === 'loose_stone') {
        let quantity = Math.max(1, parseInt(line.quantity, 10) || 1);
        const pricePerPiece = money(line.price_per_piece ?? 0);

        // A per-gram line is sold by weight: its price field is the rate per gram.
        const grams = parseFloat(line.weight_grams) || 0;
        const stoneValue = grams > 0 ? money(pricePerPiece * grams) : money(pricePerPiece * quantity);
        if (grams > 0) quantity = 1;

        return {
            ...line,
            quantity,
            gold_rate_applied: '0.00',
            gold_value: '0.00',
            making_charge_type: 'none',
            making_charge_value: '0.00',
            making_charges: '0.00',
            stone_value: stoneValue.toFixed(2),
            line_total: stoneValue.toFixed(2),
        };
    }

    const netWeight = parseFloat(line.net_weight_snapshot) || 0;
    const goldRate = money(line.gold_rate_applied ?? 0);
    const goldValue = money(netWeight * goldRate);

    const makingType = line.making_charge_type || 'none';
    const makingValue = money(line.making_charge_value ?? 0);
    let makingCharges = 0;
    if (makingType === 'fixed') makingCharges = makingValue;
    if (makingType === 'per_gram') makingCharges = money(makingValue * netWeight);

    let stoneValue = 0;
    const calculatedStones = stones.map((stone) => {
        const qty = Math.max(0, parseInt(stone.quantity, 10) || 0);
        const price = money(stone.price_per_piece ?? 0);
        const total = money(price * qty);
        stoneValue += total;
        return { ...stone, quantity: qty, price_per_piece: price.toFixed(2), total_price: total.toFixed(2) };
    });

    const lineTotal = money(goldValue + makingCharges + stoneValue);

    return {
        ...line,
        gold_rate_applied: goldRate.toFixed(2),
        gold_value: goldValue.toFixed(2),
        making_charges: makingCharges.toFixed(2),
        stone_value: stoneValue.toFixed(2),
        line_total: lineTotal.toFixed(2),
        stones: calculatedStones,
    };
}

/**
 * @param {Array<object>} lines
 * @param {object} document
 * @returns {object}
 */
export function calculateDocument(lines, document) {
    let subtotal = 0;
    lines.forEach((line) => {
        subtotal += money(line.line_total);
    });
    subtotal = money(subtotal);

    const discountType = document.discount_type || 'none';
    const discountValue = money(document.discount_value ?? 0);
    let discountAmount = 0;
    if (discountType === 'fixed') discountAmount = Math.min(discountValue, subtotal);
    if (discountType === 'percentage') discountAmount = money(subtotal * Math.min(100, discountValue) / 100);

    const afterDiscount = money(Math.max(0, subtotal - discountAmount));

    const taxType = document.tax_type || 'none';
    const taxRate = money(document.tax_rate ?? 0);
    let taxAmount = 0;
    if (taxType === 'fixed') taxAmount = taxRate;
    if (taxType === 'percentage') taxAmount = money(afterDiscount * Math.min(100, taxRate) / 100);

    // Recargo de equivalencia is charged on the same discounted base as IVA.
    const recargoRate = money(document.recargo_rate ?? 0);
    const recargoAmount = money(afterDiscount * Math.min(100, recargoRate) / 100);

    const grandTotal = money(afterDiscount + taxAmount + recargoAmount);

    return {
        subtotal: subtotal.toFixed(2),
        discount_type: discountType,
        discount_value: discountValue.toFixed(2),
        discount_amount: discountAmount.toFixed(2),
        tax_type: taxType,
        tax_rate: taxRate.toFixed(2),
        tax_amount: taxAmount.toFixed(2),
        recargo_rate: recargoRate.toFixed(3),
        recargo_amount: recargoAmount.toFixed(2),
        grand_total: grandTotal.toFixed(2),
    };
}

/**
 * @param {Array<object>} lines
 * @param {object} document
 * @returns {{lines: Array<object>, document: object}}
 */
export function recalculate(lines, document) {
    const calculatedLines = lines.map((line) => {
        const stones = line.stones || [];
        const { stones: _, ...rest } = line;
        return calculateLine(rest, stones);
    });

    return {
        lines: calculatedLines,
        document: calculateDocument(calculatedLines, document),
    };
}
