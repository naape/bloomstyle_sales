const tip = document.getElementById('chart-tip');
const rupees = (n) => `Rs. ${Math.round(n).toLocaleString('en-LK')}`;

// One tooltip for every chart. Text is set with textContent because item names are data.
function showTip(value, title, detail, x, y) {
    tip.replaceChildren();
    [['strong', value], ['span', title], ['small', detail]].forEach(([tag, text]) => {
        if (!text) return;
        const line = document.createElement(tag);
        line.textContent = text;
        tip.append(line);
    });
    tip.hidden = false;
    const box = tip.getBoundingClientRect();
    tip.style.left = `${Math.max(8, Math.min(x + 14, window.innerWidth - box.width - 8))}px`;
    tip.style.top = `${Math.max(8, y - box.height - 12)}px`;
}
const hideTip = () => { tip.hidden = true; };

document.querySelectorAll('.rank li').forEach((row) => {
    const show = (x, y) => showTip(row.dataset.tipValue, row.dataset.tipTitle, row.dataset.tipDetail, x, y);
    row.addEventListener('pointermove', (event) => show(event.clientX, event.clientY));
    row.addEventListener('pointerleave', hideTip);
    row.addEventListener('focus', () => {
        const box = row.getBoundingClientRect();
        show(box.left + box.width / 2, box.top);
    });
    row.addEventListener('blur', hideTip);
});

// Monthly trend: a line with a crosshair that snaps to the nearest month.
const holder = document.getElementById('trend');
const source = document.getElementById('trend-data');

if (holder && source) {
    const data = JSON.parse(source.textContent);
    const NS = 'http://www.w3.org/2000/svg';
    const pad = { top: 14, right: 18, bottom: 28, left: 52 };
    const height = 270;
    let active = data.length - 1;
    let place = () => {};

    const shape = (name, attributes, parent) => {
        const node = document.createElementNS(NS, name);
        Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));
        parent.append(node);
        return node;
    };

    const niceMax = (value) => {
        const step = 10 ** Math.floor(Math.log10(value || 1));
        const rounded = [1, 2, 2.5, 5, 10].map((n) => n * step).find((n) => n >= value);
        return rounded ?? 10 * step;
    };
    const tick = (n) => (n >= 1000 ? `${n / 1000}K` : String(n));

    const draw = () => {
        const width = holder.clientWidth;
        const plotW = width - pad.left - pad.right;
        const plotH = height - pad.top - pad.bottom;
        const top = niceMax(Math.max(...data.map((row) => row.revenue)));
        const x = (i) => pad.left + (data.length > 1 ? (i / (data.length - 1)) * plotW : plotW / 2);
        const y = (value) => pad.top + plotH - (value / top) * plotH;

        holder.replaceChildren();
        const svg = shape('svg', { width, height, viewBox: `0 0 ${width} ${height}`, 'aria-hidden': 'true' }, holder);

        for (let i = 0; i <= 4; i++) {
            const value = (top / 4) * i;
            shape('line', { class: 'grid', x1: pad.left, x2: width - pad.right, y1: y(value), y2: y(value) }, svg);
            shape('text', { class: 'axis', x: pad.left - 8, y: y(value) + 4, 'text-anchor': 'end' }, svg).textContent = tick(value);
        }

        const room = Math.max(1, Math.floor(plotW / 78));
        const every = Math.ceil(data.length / room);
        data.forEach((row, i) => {
            if (i % every === 0) {
                const anchor = i === 0 ? 'start' : (x(i) + 34 > width ? 'end' : 'middle');
                shape('text', { class: 'axis', x: x(i), y: height - 8, 'text-anchor': anchor }, svg).textContent = row.label;
            }
        });

        const points = data.map((row, i) => `${x(i)},${y(row.revenue)}`);
        if (data.length > 1) {
            const base = pad.top + plotH;
            shape('polygon', { class: 'area', points: `${x(0)},${base} ${points.join(' ')} ${x(data.length - 1)},${base}` }, svg);
            shape('polyline', { class: 'line', points: points.join(' ') }, svg);
        }

        const cross = shape('line', { class: 'cross', y1: pad.top, y2: pad.top + plotH }, svg);
        const dot = shape('circle', { class: 'dot', r: 5 }, svg);

        place = (i, show) => {
            active = i;
            const row = data[i];
            cross.setAttribute('x1', x(i));
            cross.setAttribute('x2', x(i));
            dot.setAttribute('cx', x(i));
            dot.setAttribute('cy', y(row.revenue));
            cross.style.visibility = show ? 'visible' : 'hidden';
            if (show) {
                const box = svg.getBoundingClientRect();
                showTip(rupees(row.revenue), row.label, `${row.orders} orders · ${row.units} units`, box.left + x(i), box.top + y(row.revenue));
            }
        };
        place(active, false);

        svg.addEventListener('pointermove', (event) => {
            const offset = event.clientX - svg.getBoundingClientRect().left;
            const ratio = (offset - pad.left) / plotW;
            place(Math.max(0, Math.min(data.length - 1, Math.round(ratio * (data.length - 1)))), true);
        });
        svg.addEventListener('pointerleave', () => { place(active, false); hideTip(); });
    };

    draw();
    new ResizeObserver(draw).observe(holder);

    holder.addEventListener('focus', () => place(active, true));
    holder.addEventListener('blur', () => { place(active, false); hideTip(); });
    holder.addEventListener('keydown', (event) => {
        const move = { ArrowLeft: -1, ArrowRight: 1 }[event.key];
        if (!move) return;
        event.preventDefault();
        place(Math.max(0, Math.min(data.length - 1, active + move)), true);
    });
}
