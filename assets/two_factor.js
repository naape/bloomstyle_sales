const qrBox = document.querySelector('[data-otpauth]');
if (qrBox) {
    const qr = qrcode(0, 'M');
    qr.addData(qrBox.dataset.otpauth);
    qr.make();
    qrBox.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 8, scalable: true });
}

document.querySelector('[data-print]')?.addEventListener('click', () => window.print());

document.querySelector('[data-copy-codes]')?.addEventListener('click', async (event) => {
    const codes = [...document.querySelectorAll('.recovery-list li')].map((item) => item.textContent).join('\n');
    await navigator.clipboard.writeText(codes);
    event.target.textContent = 'Copied';
});
