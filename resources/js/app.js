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
