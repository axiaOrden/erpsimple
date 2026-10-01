/**
 * Proof-of-payment capture.
 *
 * Flow: device camera/file picker → correct orientation where needed →
 * resize → compress → JPEG → visible watermark → upload.
 *
 * Client-side processing is an OPTIMISATION (smaller upload, readable receipt):
 * the server independently validates MIME type, that the payload really is an
 * image, the size and the authorization. If canvas processing is unavailable
 * the ORIGINAL file is still submitted — never a blocked payment.
 *
 * GPS is sent as structured fields; the watermark text is presentation only.
 */
export function paymentProof(config = {}) {
    const MAX_EDGE = Number(config.maxEdge || 1600);
    const QUALITY = Number(config.quality || 0.82);

    const round = (value, digits = 7) => Number(Number(value).toFixed(digits));

    return {
        employeeName: config.employeeName || '',
        employeeId: config.employeeId || '',
        customerName: config.customerName || '',
        invoiceNo: config.invoiceNo || '',
        amount: config.amount || '',

        previewUrl: '',
        status: '',
        error: '',
        processing: false,

        lat: null,
        lng: null,
        accuracy: null,
        capturedAt: '',
        watermarkText: '',
        deviceNote: '',

        init() {
            this.acquireGps();
        },

        // ---- GPS --------------------------------------------------------------

        acquireGps() {
            if (!('geolocation' in navigator)) {
                this.deviceNote = 'No GPS available on this device — the receipt photo is still recorded.';

                return;
            }

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    this.lat = round(position.coords.latitude);
                    this.lng = round(position.coords.longitude);
                    this.accuracy = Math.round(position.coords.accuracy);
                    this.capturedAt = new Date().toISOString();
                    this.refreshWatermark();
                },
                () => {
                    this.deviceNote = 'GPS fix unavailable — proof is recorded without coordinates.';
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
            );
        },

        // ---- capture + processing ---------------------------------------------

        async onPick(event) {
            const input = event.target;
            const file = input.files && input.files[0] ? input.files[0] : null;

            this.error = '';
            this.status = '';

            if (!file) {
                this.previewUrl = '';

                return;
            }

            this.processing = true;
            this.status = 'Preparing proof…';

            this.refreshWatermark();

            try {
                const processed = await this.process(file);

                // Replace the input's payload with the watermarked JPEG so the
                // normal form submission carries the processed image.
                const transfer = new DataTransfer();
                transfer.items.add(processed);
                input.files = transfer.files;

                this.previewUrl = URL.createObjectURL(processed);
                this.status = 'Watermarked JPEG ready (' + Math.round(processed.size / 1024) + ' KB).';
            } catch (e) {
                // Fall back to the untouched file: the server validates it.
                this.previewUrl = URL.createObjectURL(file);
                this.status = 'Saved without on-device processing.';
                this.deviceNote = 'Could not process the image on this device — the original photo is uploaded instead.';
            } finally {
                this.processing = false;
            }

            if (!this.capturedAt) this.capturedAt = new Date().toISOString();

            this.refreshWatermark();
        },

        async process(file) {
            const bitmap = await this.loadImage(file);
            const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width || bitmap.naturalWidth, bitmap.height || bitmap.naturalHeight));
            const width = Math.max(1, Math.round((bitmap.width || bitmap.naturalWidth) * scale));
            const height = Math.max(1, Math.round((bitmap.height || bitmap.naturalHeight) * scale));

            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;

            const context = canvas.getContext('2d');
            context.drawImage(bitmap, 0, 0, width, height);

            this.drawWatermark(context, width, height);

            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));

            if (!blob) throw new Error('canvas.toBlob returned null');

            return new File([blob], 'payment-proof.jpg', { type: 'image/jpeg', lastModified: Date.now() });
        },

        loadImage(file) {
            if ('createImageBitmap' in window) {
                return createImageBitmap(file, { imageOrientation: 'from-image' });
            }

            return new Promise((resolve, reject) => {
                const image = new Image();
                const url = URL.createObjectURL(file);

                image.onload = () => {
                    URL.revokeObjectURL(url);
                    resolve(image);
                };
                image.onerror = () => {
                    URL.revokeObjectURL(url);
                    reject(new Error('image decode failed'));
                };
                image.src = url;
            });
        },

        watermarkLines() {
            const lines = [
                'Simple ERP — payment proof',
                this.employeeName ? 'Sales employee: ' + this.employeeName + (this.employeeId ? ' (' + this.employeeId + ')' : '') : '',
                this.customerName ? 'Customer: ' + this.customerName : '',
                this.invoiceNo ? 'Invoice: ' + this.invoiceNo + (this.amount ? ' · amount ' + this.amount : '') : '',
                'Captured: ' + (this.capturedAt ? new Date(this.capturedAt).toLocaleString() : new Date().toLocaleString()),
                this.lat !== null && this.lng !== null ? 'GPS: ' + this.lat.toFixed(6) + ', ' + this.lng.toFixed(6) + (this.accuracy ? ' ±' + this.accuracy + 'm' : '') : '',
            ];

            return lines.filter((line) => line !== '');
        },

        drawWatermark(context, width, height) {
            const lines = this.watermarkLines();
            const fontSize = Math.max(14, Math.round(width / 46));
            const lineHeight = Math.round(fontSize * 1.35);
            const bandHeight = lines.length * lineHeight + Math.round(fontSize * 0.9);

            context.fillStyle = 'rgba(11, 87, 208, 0.72)';
            context.fillRect(0, height - bandHeight, width, bandHeight);

            context.fillStyle = '#ffffff';
            context.font = '600 ' + fontSize + 'px system-ui, sans-serif';
            context.textBaseline = 'top';

            lines.forEach((line, index) => {
                context.fillText(line, Math.round(fontSize * 0.7), height - bandHeight + Math.round(fontSize * 0.45) + index * lineHeight);
            });
        },

        refreshWatermark() {
            this.watermarkText = this.watermarkLines().join(' | ').slice(0, 255);
        },
    };
}
