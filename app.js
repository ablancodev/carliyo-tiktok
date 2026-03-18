const $ = (sel) => document.querySelector(sel);

// Elements
const form = $('#uploadForm');
const dropZone = $('#dropZone');
const fileInput = $('#videoFile');
const filePreview = $('#filePreview');
const dropContent = $('.drop-zone-content');
const videoPreview = $('#videoPreview');
const fileNameEl = $('#fileName');
const fileSizeEl = $('#fileSize');
const removeBtn = $('#removeFile');
const submitBtn = $('#submitBtn');
const statusPanel = $('#statusPanel');
const fileGroup = $('#fileUploadGroup');
const urlGroup = $('#urlUploadGroup');

let selectedFile = null;
let uploadMethod = 'file';
let publishMode = 'direct';
let statusPollTimer = null;

// Toggle upload method
document.querySelectorAll('.toggle-btn[data-method]').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.toggle-btn[data-method]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        uploadMethod = btn.dataset.method;
        fileGroup.classList.toggle('hidden', uploadMethod !== 'file');
        urlGroup.classList.toggle('hidden', uploadMethod !== 'url');
    });
});

// Toggle publish mode
document.querySelectorAll('.publish-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.publish-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        publishMode = btn.dataset.publish;
        $('#publishHint').textContent = publishMode === 'direct'
            ? 'El video se publicará automáticamente en TikTok (visibilidad: solo tú, hasta que TikTok audite la app)'
            : 'El video se enviará a tu bandeja de TikTok para revisarlo antes de publicar';
    });
});

// Drag & drop
dropZone.addEventListener('click', () => fileInput.click());
dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('drag-over');
});
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file && file.type.startsWith('video/')) handleFile(file);
});

fileInput.addEventListener('change', () => {
    if (fileInput.files[0]) handleFile(fileInput.files[0]);
});

function handleFile(file) {
    if (file.size > 50 * 1024 * 1024) {
        alert('El archivo supera los 50MB');
        return;
    }
    selectedFile = file;
    fileNameEl.textContent = file.name;
    fileSizeEl.textContent = formatSize(file.size);
    videoPreview.src = URL.createObjectURL(file);
    dropContent.classList.add('hidden');
    filePreview.classList.remove('hidden');
}

removeBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    selectedFile = null;
    fileInput.value = '';
    videoPreview.src = '';
    dropContent.classList.remove('hidden');
    filePreview.classList.add('hidden');
});

// Char counters
$('#title').addEventListener('input', (e) => {
    $('#titleCount').textContent = e.target.value.length;
});
$('#description').addEventListener('input', (e) => {
    $('#descCount').textContent = e.target.value.length;
});

// Form submit
form.addEventListener('submit', async (e) => {
    e.preventDefault();

    if (uploadMethod === 'file' && !selectedFile) return alert('Selecciona un video');
    if (uploadMethod === 'url' && !$('#videoUrl').value.trim()) return alert('Introduce la URL del video');

    setLoading(true);

    try {
        if (uploadMethod === 'url') {
            await uploadViaUrl();
        } else {
            await uploadViaFile();
        }
    } catch (err) {
        showStatus('error', 'Error al subir', err.message);
    } finally {
        setLoading(false);
    }
});

// Upload via URL (PULL_FROM_URL)
async function uploadViaUrl() {
    const res = await fetch('upload.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            method: 'url',
            publish_mode: publishMode,
            video_url: $('#videoUrl').value.trim(),
            title: $('#title').value.trim(),
            description: $('#description').value.trim()
        })
    });
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    showProcessing(data.publish_id);
}

// Upload via File (FILE_UPLOAD)
async function uploadViaFile() {
    // Step 1: Init upload
    const initRes = await fetch('upload.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            method: 'file_init',
            publish_mode: publishMode,
            video_size: selectedFile.size,
            title: $('#title').value.trim(),
            description: $('#description').value.trim()
        })
    });
    const initData = await initRes.json();
    if (initData.error) throw new Error(initData.error);

    // Step 2: Upload file to presigned URL
    const uploadRes = await fetch('upload.php', {
        method: 'POST',
        body: createUploadFormData(initData.upload_url, selectedFile)
    });
    const uploadData = await uploadRes.json();
    if (uploadData.error) throw new Error(uploadData.error);

    showProcessing(initData.publish_id);
}

function createUploadFormData(uploadUrl, file) {
    const fd = new FormData();
    fd.append('method', 'file_upload');
    fd.append('upload_url', uploadUrl);
    fd.append('video', file);
    return fd;
}

// Show processing state and start polling
function showProcessing(publishId) {
    statusPanel.className = 'status-panel processing';
    statusPanel.classList.remove('hidden');
    $('#statusIcon').className = 'status-icon';
    $('#statusTitle').textContent = 'Procesando video...';
    $('#statusMessage').textContent = 'TikTok esta procesando tu video. Esto puede tardar unos segundos.';
    $('#publishId').textContent = publishId;
    $('#publishIdContainer').classList.remove('hidden');
    $('#statusProgress').classList.remove('hidden');
    $('#newUploadBtn').classList.add('hidden');

    // Set step 1 (upload) as done, step 2 (processing) as active/pulsing
    setStep('stepUpload', 'active');
    setStepLine(0, true);
    setStep('stepProcessing', 'active pulsing');
    setStep('stepReady', '');
    setStepLine(1, false);

    statusPanel.scrollIntoView({ behavior: 'smooth' });

    // Start polling status
    startStatusPolling(publishId);
}

function startStatusPolling(publishId) {
    if (statusPollTimer) clearInterval(statusPollTimer);

    let attempts = 0;
    const maxAttempts = 30; // 30 * 5s = 2.5 min max

    statusPollTimer = setInterval(async () => {
        attempts++;
        if (attempts > maxAttempts) {
            clearInterval(statusPollTimer);
            showStatus('error', 'Tiempo agotado',
                'No se pudo confirmar el estado del video. Revisa tu bandeja de TikTok manualmente.');
            return;
        }

        try {
            const res = await fetch('upload.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ method: 'status', publish_id: publishId })
            });
            const data = await res.json();
            const status = data?.data?.status;

            if (status === 'PUBLISH_COMPLETE' || status === 'SEND_TO_USER_INBOX') {
                clearInterval(statusPollTimer);
                setStep('stepProcessing', 'active');
                setStepLine(1, true);
                setStep('stepReady', 'active');
                statusPanel.className = 'status-panel success';
                $('#statusIcon').className = 'status-icon';
                if (status === 'PUBLISH_COMPLETE') {
                    $('#statusTitle').textContent = 'Video publicado';
                    $('#statusMessage').textContent = 'Tu video se ha publicado en TikTok.';
                } else {
                    $('#statusTitle').textContent = 'Video listo';
                    $('#statusMessage').textContent = 'Tu video esta en la bandeja de TikTok. Abre la app para revisarlo y publicarlo.';
                }
                $('#newUploadBtn').classList.remove('hidden');
            } else if (status === 'FAILED') {
                clearInterval(statusPollTimer);
                const reason = data?.data?.fail_reason || 'Error desconocido';
                setStep('stepProcessing', 'failed');
                statusPanel.className = 'status-panel error';
                $('#statusIcon').className = 'status-icon';
                $('#statusTitle').textContent = 'Error de procesamiento';
                $('#statusMessage').innerHTML = 'TikTok no pudo procesar el video.<div class="fail-reason">' + escapeHtml(reason) + '</div>';
                $('#newUploadBtn').classList.remove('hidden');
            }
            // PROCESSING_UPLOAD / PROCESSING_DOWNLOAD → keep polling
        } catch (err) {
            // Network error, keep trying
        }
    }, 5000);
}

function setStep(stepId, classes) {
    const el = $('#' + stepId);
    el.className = 'step' + (classes ? ' ' + classes : '');
}

function setStepLine(index, active) {
    const lines = document.querySelectorAll('.step-line');
    if (lines[index]) {
        lines[index].classList.toggle('active', active);
    }
}

function showStatus(type, title, message) {
    statusPanel.className = `status-panel ${type}`;
    statusPanel.classList.remove('hidden');
    $('#statusIcon').className = 'status-icon';
    $('#statusTitle').textContent = title;
    $('#statusMessage').textContent = message;
    $('#statusProgress').classList.add('hidden');
    $('#publishIdContainer').classList.add('hidden');
    $('#newUploadBtn').classList.remove('hidden');
    statusPanel.scrollIntoView({ behavior: 'smooth' });
}

function setLoading(loading) {
    submitBtn.disabled = loading;
    $('.btn-text').classList.toggle('hidden', loading);
    $('.btn-loading').classList.toggle('hidden', !loading);
}

$('#newUploadBtn').addEventListener('click', () => {
    if (statusPollTimer) clearInterval(statusPollTimer);
    statusPanel.classList.add('hidden');
    form.reset();
    removeBtn.click();
    $('#titleCount').textContent = '0';
    $('#descCount').textContent = '0';
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
