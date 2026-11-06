document.querySelector('[data-print]')?.addEventListener('click', () => window.print());

// Journal form: add and remove lines, and show whether debits equal credits.
const journal = document.querySelector('form[data-journal]');
if (journal) {
    const lines = document.getElementById('journal-lines');
    const text = (id, value) => { document.getElementById(id).textContent = value; };
    const money = (n) => n.toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const sum = (name) => [...journal.querySelectorAll(`[name="${name}[]"]`)]
        .reduce((total, input) => total + (parseFloat(input.value) || 0), 0);

    const update = () => {
        const debit = sum('debit');
        const credit = sum('credit');
        text('journal-debit', money(debit));
        text('journal-credit', money(credit));
        text('journal-difference', money(Math.abs(debit - credit)));
        const only = lines.children.length <= 2;
        lines.querySelectorAll('.line-remove').forEach((button) => { button.hidden = only; });
    };

    document.getElementById('add-journal-line').addEventListener('click', () => {
        lines.append(document.getElementById('journal-template').content.cloneNode(true));
        update();
    });
    lines.addEventListener('click', (event) => {
        const button = event.target.closest('.line-remove');
        if (button) {
            button.closest('.journal-line').remove();
            update();
        }
    });
    journal.addEventListener('input', update);
    update();
}
