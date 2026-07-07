let notificationSequence = 0;

function notificationContainer() {
    let container = document.getElementById('notification-container');

    if (!container) {
        container = document.createElement('div');
        container.id = 'notification-container';
        container.className = 'fixed flex flex-col gap-3';
        container.style.cssText = 'z-index:9999999;top:20px;right:20px;position:fixed;max-width:min(420px,calc(100vw - 40px));';
        document.body.appendChild(container);
    }

    return container;
}

function showNotification(message, type = 'success') {
    const normalizedMessage = String(message || 'An unknown error occurred.');
    const container = notificationContainer();
    const duplicate = Array.from(container.querySelectorAll('.notification')).find(notification => (
        notification.dataset.message === normalizedMessage
        && notification.dataset.type === type
    ));

    if (duplicate) {
        return;
    }

    const notificationId = `notification-${Date.now()}-${++notificationSequence}`;
    const notification = document.createElement('div');
    notification.id = notificationId;
    notification.dataset.message = normalizedMessage;
    notification.dataset.type = type;
    notification.className = `${type === 'success' ? 'bg-green-500' : 'bg-red-500'} text-white px-6 py-3 rounded shadow-lg notification`;
    notification.style.cssText = 'display:block;visibility:visible;opacity:1;transition:transform .3s ease-out,opacity .3s ease-out;';

    const content = document.createElement('div');
    content.className = 'flex items-start justify-between gap-4';

    const text = document.createElement('span');
    text.className = 'break-words';
    text.textContent = normalizedMessage;

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'shrink-0 text-white hover:text-gray-200';
    closeButton.setAttribute('aria-label', 'Close notification');
    closeButton.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
    closeButton.addEventListener('click', () => closeNotification(notificationId));

    content.append(text, closeButton);
    notification.appendChild(content);
    container.appendChild(notification);

    window.setTimeout(() => closeNotification(notificationId), 7000);
}

function closeNotification(notificationId) {
    const notification = document.getElementById(notificationId);

    if (!notification) {
        return;
    }

    notification.style.transform = 'translateX(110%)';
    notification.style.opacity = '0';

    window.setTimeout(() => {
        notification.remove();

        const container = document.getElementById('notification-container');

        if (container && !container.children.length) {
            container.remove();
        }
    }, 300);
}
