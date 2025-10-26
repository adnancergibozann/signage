(function () {
    'use strict';

    const config = window.__LAYOUT_CONFIG__ || {};
    const stage = document.getElementById('design-stage');
    if (!stage) {
        return;
    }

    const modules = Array.from(stage.querySelectorAll('.design-module'));
    const moduleMap = new Map();
    modules.forEach(module => {
        moduleMap.set(module.dataset.key, module);
    });

    const inputMap = {};
    document.querySelectorAll('[data-module-input]').forEach(input => {
        const key = input.getAttribute('data-key');
        const field = input.getAttribute('data-field');
        if (!key || !field) {
            return;
        }
        if (!inputMap[key]) {
            inputMap[key] = {};
        }
        inputMap[key][field] = input;
    });

    function clamp(value, min, max) {
        return Math.min(Math.max(value, min), max);
    }

    function applyLayout(module) {
        const top = parseFloat(module.dataset.top || '0');
        const left = parseFloat(module.dataset.left || '0');
        const width = parseFloat(module.dataset.width || '20');
        const height = parseFloat(module.dataset.height || '20');
        const fontScale = parseFloat(module.dataset.fontScale || '1');
        const zIndex = parseInt(module.dataset.zIndex || '1', 10);

        module.style.top = top + '%';
        module.style.left = left + '%';
        module.style.width = width + '%';
        module.style.height = height + '%';
        module.style.zIndex = String(zIndex);
        module.style.setProperty('--module-font-scale', fontScale);
    }

    function updateGeometry(module) {
        const geometry = module.querySelector('[data-role="geometry"]');
        if (!geometry) {
            return;
        }
        const width = parseFloat(module.dataset.width || '0').toFixed(1);
        const height = parseFloat(module.dataset.height || '0').toFixed(1);
        geometry.textContent = `${width}% × ${height}%`;
    }

    function syncInputs(module) {
        const key = module.dataset.key;
        const inputs = inputMap[key];
        if (!inputs) {
            return;
        }
        Object.entries(inputs).forEach(([field, input]) => {
            let value = module.dataset[field];
            if (typeof value === 'undefined') {
                if (field === 'fontScale') {
                    value = module.dataset.fontScale;
                } else if (field === 'zIndex') {
                    value = module.dataset.zIndex;
                }
            }
            if (typeof value !== 'undefined' && input) {
                input.value = parseFloat(value).toFixed(field === 'zIndex' ? 0 : 2);
            }
        });
    }

    function commit(module) {
        applyLayout(module);
        updateGeometry(module);
        syncInputs(module);
    }

    function pointerHandler(module, mode, startEvent) {
        const rect = stage.getBoundingClientRect();
        const start = {
            x: startEvent.clientX,
            y: startEvent.clientY,
            top: parseFloat(module.dataset.top || '0'),
            left: parseFloat(module.dataset.left || '0'),
            width: parseFloat(module.dataset.width || '20'),
            height: parseFloat(module.dataset.height || '20')
        };

        function onMove(event) {
            event.preventDefault();
            const dx = ((event.clientX - start.x) / rect.width) * 100;
            const dy = ((event.clientY - start.y) / rect.height) * 100;

            if (mode === 'drag') {
                const newLeft = clamp(start.left + dx, 0, 100 - start.width);
                const newTop = clamp(start.top + dy, 0, 100 - start.height);
                module.dataset.left = newLeft.toFixed(2);
                module.dataset.top = newTop.toFixed(2);
            } else if (mode === 'resize') {
                const newWidth = clamp(start.width + dx, 5, 100 - start.left);
                const newHeight = clamp(start.height + dy, 5, 100 - start.top);
                module.dataset.width = newWidth.toFixed(2);
                module.dataset.height = newHeight.toFixed(2);
            }

            commit(module);
        }

        function onUp(event) {
            event.preventDefault();
            module.releasePointerCapture(event.pointerId);
            module.removeEventListener('pointermove', onMove);
            module.removeEventListener('pointerup', onUp);
            module.removeEventListener('pointercancel', onUp);
        }

        module.setPointerCapture(startEvent.pointerId);
        module.addEventListener('pointermove', onMove);
        module.addEventListener('pointerup', onUp);
        module.addEventListener('pointercancel', onUp);
    }

    modules.forEach(module => {
        applyLayout(module);
        updateGeometry(module);
    });

    modules.forEach(module => {
        const handle = module.querySelector('.design-drag-handle');
        if (handle) {
            handle.addEventListener('pointerdown', event => {
                event.preventDefault();
                pointerHandler(module, 'drag', event);
            });
        }

        const resize = module.querySelector('.design-resize-handle');
        if (resize) {
            resize.addEventListener('pointerdown', event => {
                event.preventDefault();
                pointerHandler(module, 'resize', event);
            });
        }

        syncInputs(module);
    });

    Object.entries(inputMap).forEach(([key, fields]) => {
        Object.entries(fields).forEach(([field, input]) => {
            input.addEventListener('input', () => {
                const module = moduleMap.get(key);
                if (!module) {
                    return;
                }
                const value = parseFloat(input.value);
                if (Number.isNaN(value)) {
                    return;
                }
                if (field === 'fontScale') {
                    module.dataset.fontScale = clamp(value, 0.25, 4).toFixed(2);
                } else if (field === 'zIndex') {
                    module.dataset.zIndex = clamp(Math.round(value), 0, 20).toString();
                } else {
                    const limits = field === 'width' || field === 'height' ? [5, 100] : [0, 100];
                    const clamped = clamp(value, limits[0], limits[1]);
                    module.dataset[field] = clamped.toFixed(2);
                }
                commit(module);
            });
        });
    });

    const defaultsButton = document.querySelector('[data-role="apply-defaults"]');
    if (defaultsButton && config.defaults) {
        defaultsButton.addEventListener('click', () => {
            modules.forEach(module => {
                const key = module.dataset.key;
                const defaults = config.defaults[key];
                if (!defaults) {
                    return;
                }
                module.dataset.top = parseFloat(defaults.top).toFixed(2);
                module.dataset.left = parseFloat(defaults.left).toFixed(2);
                module.dataset.width = parseFloat(defaults.width).toFixed(2);
                module.dataset.height = parseFloat(defaults.height).toFixed(2);
                module.dataset.fontScale = parseFloat(defaults.font_scale ?? defaults.fontScale ?? 1).toFixed(2);
                module.dataset.zIndex = String(defaults.z_index ?? defaults.zIndex ?? 1);
                commit(module);
            });
        });
    }

    const saveForm = document.getElementById('layout-save-form');
    const payloadInput = document.getElementById('layout-payload');
    if (saveForm && payloadInput) {
        saveForm.addEventListener('submit', event => {
            const payload = {};
            moduleMap.forEach((module, key) => {
                payload[key] = {
                    top: parseFloat(module.dataset.top || '0'),
                    left: parseFloat(module.dataset.left || '0'),
                    width: parseFloat(module.dataset.width || '0'),
                    height: parseFloat(module.dataset.height || '0'),
                    font_scale: parseFloat(module.dataset.fontScale || '1'),
                    z_index: parseInt(module.dataset.zIndex || '1', 10)
                };
            });
            payloadInput.value = JSON.stringify(payload);
        });
    }
})();
