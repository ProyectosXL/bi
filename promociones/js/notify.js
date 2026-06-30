/**
 * Sistema de notificaciones Premium (Toast) integrado visualmente con la estética de Antigravity/Promociones.
 */
const PromoNotify = (() => {
    let container = null;

    function init() {
        if (container) return;
        container = document.createElement('div');
        container.id = 'promo-toast-container';
        container.style.cssText = `
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 999999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        `;
        document.body.appendChild(container);

        // Estilos de los toasts
        const style = document.createElement('style');
        style.textContent = `
            .promo-toast {
                min-width: 280px;
                max-width: 400px;
                background: #1e293b;
                color: #ffffff;
                border-left: 4px solid #00a878;
                border-radius: 6px;
                padding: 14px 18px;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.15);
                font-family: 'Inter', sans-serif;
                font-size: 0.9rem;
                font-weight: 500;
                line-height: 1.4;
                pointer-events: auto;
                opacity: 0;
                transform: translateY(20px);
                transition: opacity 0.3s ease, transform 0.3s ease;
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .promo-toast.show {
                opacity: 1;
                transform: translateY(0);
            }
            .promo-toast.error {
                border-left-color: #ef4444;
            }
            .promo-toast.info {
                border-left-color: #3b82f6;
            }
            .promo-toast-icon {
                font-size: 1.25rem;
                flex-shrink: 0;
            }
        `;
        document.head.appendChild(style);
    }

    function show(message, type = 'success', duration = 3500) {
        init();
        const toast = document.createElement('div');
        toast.className = `promo-toast ${type}`;
        
        let iconHtml = '<i class="bi bi-check-circle-fill" style="color:#10b981;"></i>';
        if (type === 'error') {
            iconHtml = '<i class="bi bi-exclamation-triangle-fill" style="color:#ef4444;"></i>';
        } else if (type === 'info') {
            iconHtml = '<i class="bi bi-info-circle-fill" style="color:#3b82f6;"></i>';
        }

        toast.innerHTML = `
            <div class="promo-toast-icon">${iconHtml}</div>
            <div style="flex-grow:1;">${message}</div>
        `;
        
        container.appendChild(toast);
        
        // Trigger show animation
        setTimeout(() => toast.classList.add('show'), 50);

        // Auto remove
        setTimeout(() => {
            toast.classList.remove('show');
            toast.style.transform = 'translateY(10px)';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    return {
        success: (msg, dur) => show(msg, 'success', dur),
        error: (msg, dur) => show(msg, 'error', dur),
        info: (msg, dur) => show(msg, 'info', dur),
    };
})();
window.PromoNotify = PromoNotify;
