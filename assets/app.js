const amount = (input) => parseFloat(input?.value) || 0;
const format = (n) => n.toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// Dropdowns in tables and filters apply as soon as they change.
document.querySelectorAll('select[data-autosubmit]').forEach((select) => {
    select.addEventListener('change', () => select.form.submit());
});

document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!confirm(form.dataset.confirm)) event.preventDefault();
    });
});

document.querySelector('[data-reveal]')?.addEventListener('click', (event) => {
    const button = event.currentTarget;
    const input = button.previousElementSibling;
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
});

// Items page: narrow the product cards as you type.
const itemSearch = document.getElementById('item-search');
if (itemSearch) {
    const cards = document.querySelectorAll('.product');
    const noMatch = document.getElementById('no-match');
    itemSearch.addEventListener('input', () => {
        const term = itemSearch.value.trim().toLowerCase();
        let visible = 0;
        cards.forEach((card) => {
            card.hidden = !card.dataset.search.includes(term);
            if (!card.hidden) visible++;
        });
        noMatch.hidden = visible > 0;
    });
}

// Sale and stock forms: fill details from the chosen item and keep the totals current.
const form = document.querySelector('form[data-entry]');
const byId = (id) => document.getElementById(id);

if (form?.dataset.entry === 'stock') {
    const el = (name) => form.elements[name];
    const updateTotal = () => { byId('total').value = format(amount(el('qty')) * amount(el('unit_price')) || 0); };
    const showItem = () => { el('description').value = el('item_id').selectedOptions[0]?.dataset.description ?? ''; };
    el('item_id').addEventListener('change', () => {
        showItem();
        const cost = el('item_id').selectedOptions[0]?.dataset.cost;
        if (cost) el('unit_price').value = cost;
        updateTotal();
    });
    form.addEventListener('input', updateTotal);
    showItem();
    updateTotal();
}

if (form?.dataset.entry === 'sale') {
    const lines = byId('lines');
    const allLines = () => [...lines.querySelectorAll('.line')];
    const field = (line, name) => line.querySelector(`[name="${name}[]"]`);

    const showItem = (line) => {
        const option = field(line, 'item_id').selectedOptions[0];
        line.querySelector('.line-description').value = option?.dataset.description ?? '';
        line.querySelector('.line-stock').textContent = option?.value ? `In stock: ${option.dataset.stock}` : '';
    };

    const updateTotals = () => {
        const summary = byId('sum-lines');
        summary.replaceChildren();
        let subtotal = 0;

        allLines().forEach((line) => {
            const qty = amount(field(line, 'qty'));
            const lineTotal = qty * amount(field(line, 'unit_price'));
            subtotal += lineTotal;
            line.querySelector('.line-amount').textContent = format(lineTotal);

            const option = field(line, 'item_id').selectedOptions[0];
            if (option?.value) {
                const row = document.createElement('li');
                const name = document.createElement('span');
                const value = document.createElement('span');
                name.textContent = `${qty} × ${option.dataset.description}`;
                value.textContent = format(lineTotal);
                row.append(name, value);
                summary.append(row);
            }
        });

        const delivery = amount(form.elements.delivery_charge);
        byId('sum-empty').hidden = summary.children.length > 0;
        byId('sum-subtotal').textContent = `Rs. ${format(subtotal)}`;
        byId('sum-delivery').textContent = `Rs. ${format(delivery)}`;
        byId('total').value = `Rs. ${format(subtotal + delivery)}`;

        const only = allLines().length === 1;
        lines.querySelectorAll('.line-remove').forEach((button) => { button.hidden = only; });
    };

    lines.addEventListener('change', (event) => {
        if (event.target.name !== 'item_id[]') return;
        const line = event.target.closest('.line');
        showItem(line);
        field(line, 'unit_price').value = event.target.selectedOptions[0]?.dataset.price ?? '';
        updateTotals();
    });

    lines.addEventListener('click', (event) => {
        const button = event.target.closest('.line-remove');
        if (!button) return;
        button.closest('.line').remove();
        updateTotals();
    });

    byId('add-line').addEventListener('click', () => {
        lines.append(byId('line-template').content.cloneNode(true));
        const added = allLines().at(-1);
        showItem(added);
        updateTotals();
        field(added, 'item_id').focus();
    });

    form.addEventListener('input', updateTotals);

    const district = form.elements.district;
    const showProvince = () => { form.elements.province.value = district.selectedOptions[0]?.dataset.province ?? ''; };
    district.addEventListener('change', showProvince);

    showProvince();
    allLines().forEach(showItem);
    updateTotals();
}
