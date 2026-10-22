import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';

window.Swal = Swal;

const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3200,
    timerProgressBar: true,
    didOpen: (toast) => {
        toast.onmouseenter = Swal.stopTimer;
        toast.onmouseleave = Swal.resumeTimer;
    },
});

window.showToast = (type, message) => {
    if (! message) {
        return;
    }

    Toast.fire({
        icon: type === 'error' ? 'error' : type === 'warning' ? 'warning' : 'success',
        title: message,
    });
};

window.confirmAction = async (options = {}) => {
    const result = await Swal.fire({
        title: options.title || 'Are you sure?',
        text: options.text || '',
        icon: options.icon || 'warning',
        showCancelButton: true,
        confirmButtonColor: options.confirmButtonColor || '#163044',
        cancelButtonColor: '#94a3b8',
        confirmButtonText: options.confirmButtonText || 'Yes',
        cancelButtonText: options.cancelButtonText || 'Cancel',
        reverseButtons: true,
    });

    return result.isConfirmed;
};

const bimbsecOpen = async (url, action = 'print') => {
    const preview = window.open(url, '_blank');
    if (preview) {
        return;
    }
    // shortcut: popup blocked after the SweetAlert wait; one confirm restores the click
    const ready = await Swal.fire({
        icon: 'info',
        title: action === 'print' ? 'Ready to open' : 'Ready to download',
        text: 'Continue to open the PDF.',
        confirmButtonText: action === 'print' ? 'Open' : 'Download',
        confirmButtonColor: '#163044',
    });
    if (ready.isConfirmed) {
        window.open(url, '_blank') || window.location.assign(url);
    }
};

window.printPdf = (url) => {
    window.open(url, '_blank') || window.location.assign(url);
};

const bimbsecEscape = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');

const bimbsecProgressHtml = (progress) => {
    if (! progress) {
        return '<p class="text-sm text-slate-600">Please wait.</p>';
    }
    const percent = Math.max(0, Math.min(100, Number(progress.percent) || 0));
    const label = bimbsecEscape(progress.label || 'Please wait.');
    const done = Number(progress.done) || 0;
    const total = Number(progress.total) || 0;
    const count = total > 0 ? `<p class="mt-2 text-xs text-slate-500">${done} of ${total}</p>` : '';

    return `
        <p class="text-sm text-slate-600">${label}</p>
        <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-200">
            <div style="width:${percent}%" class="h-full rounded-full bg-[#163044] transition-all duration-300"></div>
        </div>
        ${count}
    `;
};

const bimbsecLoader = (title, progress = null) => {
    const html = bimbsecProgressHtml(progress);
    if (Swal.isVisible()) {
        Swal.update({ title, html });
        return;
    }
    Swal.fire({
        title,
        html,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
    });
};

const closeBimbsecLoader = () => {
    if (Swal.isVisible()) {
        Swal.close();
    }
};

const bimbsecFailed = async (message) => {
    closeBimbsecLoader();
    await Swal.fire({
        icon: 'error',
        title: 'Could not prepare the PDF',
        text: message || 'Try again.',
        confirmButtonColor: '#163044',
    });
};

const bimbsecPayload = (value) => (value && typeof value === 'object' ? value : {});

window.prepareBimbsecPrint = async (wire, customerId) => {
    const title = 'Preparing print';
    bimbsecLoader(title, { done: 0, total: 1, percent: 15, label: 'Generating PDF…' });
    try {
        const result = bimbsecPayload(await wire.call('printCustomer', customerId));
        if (! result.ok || ! result.url) {
            await bimbsecFailed(result.message);
            return;
        }
        bimbsecLoader(title, { done: 1, total: 1, percent: 100, label: 'Opening PDF…' });
        closeBimbsecLoader();
        await bimbsecOpen(result.url, 'print');
    } catch (error) {
        await bimbsecFailed();
    }
};

const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

const industryStatusLabel = (status) => {
    if (status === 'filled') {
        return { text: 'Filled', color: '#15803d' };
    }
    if (status === 'failed') {
        return { text: 'Failed', color: '#b91c1c' };
    }
    return { text: 'Skipped', color: '#64748b' };
};

window.showIndustrySuggestSummary = async (result) => {
    const rows = Array.isArray(result.results) ? result.results : [];
    const list = rows.length
        ? `<div style="max-height:16rem;overflow-y:auto;margin-top:0.75rem;padding-right:0.25rem;text-align:left;">
            <ul style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:0.5rem;font-size:0.875rem;">
                ${rows.map((row) => {
                    const status = industryStatusLabel(row.status);
                    const detail = row.detail ? `<div style="color:#64748b;margin-top:0.125rem;">${escapeHtml(row.detail)}</div>` : '';
                    return `<li style="border:1px solid #e2e8f0;border-radius:0.375rem;padding:0.5rem 0.75rem;"><div style="font-weight:600;color:${status.color};">${status.text}</div><div style="color:#1e293b;">${escapeHtml(row.name)}</div>${detail}</li>`;
                }).join('')}
            </ul>
        </div>`
        : '';

    await Swal.fire({
        icon: result.type === 'error' ? 'error' : 'success',
        title: 'Industry suggestion summary',
        html: `<p style="margin:0;font-size:0.875rem;color:#475569;">${escapeHtml(result.message || 'Done.')}</p>${list}`,
        confirmButtonText: 'OK',
        confirmButtonColor: '#163044',
        width: rows.length > 3 ? '32rem' : undefined,
        heightAuto: true,
        scrollbarPadding: false,
        didOpen: (popup) => {
            popup.style.overflow = 'hidden';
            const html = popup.querySelector('.swal2-html-container');
            if (html) {
                html.style.overflow = 'visible';
                html.style.maxHeight = 'none';
            }
        },
    });
};

window.prepareIndustrySuggest = async (wire) => {
    let selected = [];
    try {
        selected = await wire.get('selected');
    } catch (error) {
        selected = wire.selected || [];
    }
    if (! Array.isArray(selected) || selected.length === 0) {
        window.showToast('error', 'Select at least one customer.');
        return;
    }

    const confirmed = await window.confirmAction({
        title: 'Fill missing industry details?',
        text: 'For each selected customer, AI fills industry and sub-industry together on every job where either field is blank (prefers primary current, then any current, then other employment). Large selections run one customer at a time with progress.',
        confirmButtonText: 'Continue',
    });
    if (! confirmed) {
        return;
    }

    const title = 'Suggesting industry';
    bimbsecLoader(title, { done: 0, total: selected.length, percent: 0, label: 'Starting…' });
    try {
        let result = bimbsecPayload(await wire.call('beginIndustrySuggest'));
        if (! result.ok) {
            closeBimbsecLoader();
            window.showToast('error', result.message || 'Select at least one customer.');
            return;
        }
        let steps = 0;
        while (result.pending && steps < 500) {
            bimbsecLoader(title, result);
            steps += 1;
            result = bimbsecPayload(await wire.call('advanceIndustrySuggest'));
        }
        closeBimbsecLoader();
        if (result.pending) {
            window.showToast('error', 'Stopped after too many steps. Run Suggest again for the remaining customers.');
            return;
        }
        await window.showIndustrySuggestSummary(result);
    } catch (error) {
        closeBimbsecLoader();
        window.showToast('error', 'Could not fill industry.');
    }
};

window.prepareBimbsec = async (wire, action) => {
    const titles = { print: 'Preparing print', download: 'Preparing download', generate: 'Generating PDFs' };
    const title = titles[action] || 'Preparing PDF';
    const start = action === 'print' ? 'printAll' : action === 'generate' ? 'generateSelected' : 'downloadAll';
    bimbsecLoader(title, { done: 0, total: 0, percent: 0, label: 'Starting…' });
    try {
        let result = bimbsecPayload(await wire.call(start));
        let steps = 0;
        while (result.pending && result.batchId && steps < 500) {
            bimbsecLoader(title, result);
            steps += 1;
            result = bimbsecPayload(await wire.call('batchAdvance', result.batchId, action));
        }
        if (! result.ok || ! result.url) {
            await bimbsecFailed(result.message);
            return;
        }
        bimbsecLoader(title, {
            done: result.done,
            total: result.total,
            percent: 100,
            label: action === 'print' ? 'Opening PDF…' : action === 'generate' ? 'Opening batch…' : 'Starting download…',
        });
        closeBimbsecLoader();
        if (action === 'generate') {
            if (window.Livewire?.navigate) {
                window.Livewire.navigate(result.url);
            } else {
                window.location.assign(result.url);
            }
            return;
        }
        await bimbsecOpen(result.url, action);
    } catch (error) {
        await bimbsecFailed();
    }
};

const matchedModal = (message) => Swal.fire({
    icon: 'success',
    title: 'Matched',
    text: message || 'The applicant was matched to the customer.',
    confirmButtonColor: '#163044',
    confirmButtonText: 'OK',
});

let matchLoaderOpen = false;
let pendingMatchMessage = null;

window.matchWithLoader = async (wire, method, title, ...args) => {
    pendingMatchMessage = null;
    matchLoaderOpen = true;
    Swal.fire({
        title: title || 'Matching',
        text: 'Please wait.',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => {
            Swal.showLoading();
        },
    });
    try {
        await wire[method](...args);
    } finally {
        matchLoaderOpen = false;
        if (Swal.isVisible()) {
            Swal.close();
        }
    }
    if (pendingMatchMessage !== null) {
        const message = pendingMatchMessage;
        pendingMatchMessage = null;
        await matchedModal(message);
    }
};

document.addEventListener('livewire:init', () => {
    Livewire.on('notify', (payload) => {
        const data = Array.isArray(payload) ? payload[0] : payload;
        window.showToast(data?.type || 'success', data?.message || '');
    });

    Livewire.on('matched', (payload) => {
        const data = Array.isArray(payload) ? payload[0] : payload;
        const message = data?.message || 'The applicant was matched to the customer.';
        if (matchLoaderOpen) {
            pendingMatchMessage = message;
            return;
        }
        matchedModal(message);
    });
});

document.addEventListener('DOMContentLoaded', () => {
    const flash = document.getElementById('flash-alerts');
    if (! flash) {
        return;
    }

    const status = flash.dataset.status;
    const error = flash.dataset.error;

    if (status) {
        window.showToast('success', status);
    }

    if (error) {
        window.showToast('error', error);
    }
});
