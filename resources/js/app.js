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

window.prepareBimbsec = async (wire, action) => {
    const title = action === 'print' ? 'Preparing print' : 'Preparing download';
    bimbsecLoader(title, { done: 0, total: 0, percent: 0, label: 'Starting…' });
    try {
        let result = bimbsecPayload(await wire.call(action === 'print' ? 'printAll' : 'downloadAll'));
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
        bimbsecLoader(title, { done: result.done, total: result.total, percent: 100, label: action === 'print' ? 'Opening PDF…' : 'Starting download…' });
        closeBimbsecLoader();
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
