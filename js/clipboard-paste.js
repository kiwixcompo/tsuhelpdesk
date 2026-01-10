/**
 * Clipboard Paste Image Handler
 * Allows users to paste images from clipboard (screenshots, copied images, etc.)
 * into file input fields
 */

class ClipboardPasteHandler {
    constructor(options = {}) {
        this.options = {
            maxFileSize: options.maxFileSize || 5 * 1024 * 1024, // 5MB default
            allowedTypes: options.allowedTypes || ['image/png', 'image/jpeg', 'image/jpg', 'image/gif'],
            quality: options.quality || 0.8, // JPEG quality for conversion
            ...options
        };
        
        this.pastedFiles = new Map(); // Store pasted files with unique IDs
        this.textareaFileMap = new Map(); // Map textareas to their file inputs
        this.init();
    }
    
    init() {
        this.bindGlobalPasteEvent();
        this.enhanceTextareas();
        this.addStyles();
    }
    
    addStyles() {
        if (document.getElementById('clipboard-paste-styles')) return;
        
        const styles = `
            <style id="clipboard-paste-styles">
                .textarea-attachment-container {
                    position: relative;
                    display: inline-block;
                    width: 100%;
                }
                
                .attachment-icon {
                    position: absolute;
                    bottom: 8px;
                    right: 8px;
                    background: #007bff;
                    color: white;
                    border: none;
                    border-radius: 50%;
                    width: 32px;
                    height: 32px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    cursor: pointer;
                    font-size: 14px;
                    transition: all 0.3s ease;
                    z-index: 10;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                }
                
                .attachment-icon:hover {
                    background: #0056b3;
                    transform: scale(1.1);
                }
                
                .attachment-icon.has-files {
                    background: #28a745;
                }
                
                .textarea-with-paste {
                    padding-right: 45px !important;
                    border: 2px solid #dee2e6;
                    transition: border-color 0.3s ease;
                }
                
                .textarea-with-paste:focus {
                    border-color: #007bff;
                    box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
                }
                
                .textarea-with-paste.paste-active {
                    border-color: #28a745;
                    background-color: #f8fff9;
                }
                
                .pasted-image-preview {
                    display: inline-block;
                    margin: 5px;
                    position: relative;
                    border: 1px solid #dee2e6;
                    border-radius: 4px;
                    overflow: hidden;
                    background: white;
                }
                
                .pasted-image-preview img {
                    max-width: 150px;
                    max-height: 150px;
                    object-fit: cover;
                    display: block;
                }
                
                .pasted-image-preview .remove-btn {
                    position: absolute;
                    top: 5px;
                    right: 5px;
                    background: rgba(220, 53, 69, 0.8);
                    color: white;
                    border: none;
                    border-radius: 50%;
                    width: 24px;
                    height: 24px;
                    font-size: 12px;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                
                .pasted-image-preview .remove-btn:hover {
                    background: rgba(220, 53, 69, 1);
                }
                
                .paste-instructions {
                    color: #6c757d;
                    font-size: 0.9rem;
                    margin-top: 10px;
                }
                
                .paste-success {
                    color: #28a745;
                    font-size: 0.9rem;
                    margin-top: 5px;
                }
                
                .paste-error {
                    color: #dc3545;
                    font-size: 0.9rem;
                    margin-top: 5px;
                }
                
                @keyframes fadeIn {
                    from { opacity: 0; transform: translateY(-10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                
                .pasted-image-preview {
                    animation: fadeIn 0.3s ease;
                }
            </style>
        `;
        
        document.head.insertAdjacentHTML('beforeend', styles);
    }
    
    bindGlobalPasteEvent() {
        document.addEventListener('paste', (e) => {
            const activeElement = document.activeElement;
            
            // Check if we're pasting into a textarea that has attachment support
            if (activeElement && activeElement.tagName === 'TEXTAREA' && 
                activeElement.classList.contains('textarea-with-paste') && 
                e.clipboardData && e.clipboardData.items) {
                
                const items = Array.from(e.clipboardData.items);
                const imageItems = items.filter(item => item.type.startsWith('image/'));
                
                if (imageItems.length > 0) {
                    e.preventDefault(); // Prevent default paste for images
                    this.handleTextareaPaste(e, activeElement);
                }
            }
        });
    }
    
    enhanceTextareas() {
        // Find textareas that should have attachment support, but exclude those with specific IDs that will be manually initialized
        const textareas = document.querySelectorAll('textarea[name*="text"], textarea[name*="feedback"], textarea[name*="reply"]');
        
        textareas.forEach(textarea => {
            // Skip textareas that will be manually initialized
            if (textarea.id === 'payment_feedback' || textarea.classList.contains('manual-clipboard-init')) {
                return;
            }
            this.enhanceTextarea(textarea);
        });
    }
    
    enhanceTextarea(textarea) {
        // Skip if already enhanced
        if (textarea.classList.contains('textarea-with-paste')) {
            return;
        }
        
        // Create container wrapper
        const container = document.createElement('div');
        container.className = 'textarea-attachment-container';
        
        // Wrap textarea
        textarea.parentNode.insertBefore(container, textarea);
        container.appendChild(textarea);
        
        // Add classes to textarea
        textarea.classList.add('textarea-with-paste');
        
        // Create hidden file input
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/*';
        fileInput.multiple = true;
        fileInput.style.display = 'none';
        
        // Generate unique name for file input
        const textareaName = textarea.name || 'text';
        fileInput.name = textareaName.replace('_text', '_images').replace('text', 'images') + '[]';
        
        container.appendChild(fileInput);
        
        // Create attachment icon
        const attachIcon = document.createElement('button');
        attachIcon.type = 'button';
        attachIcon.className = 'attachment-icon';
        attachIcon.innerHTML = '<i class="fas fa-paperclip"></i>';
        attachIcon.title = 'Attach images (or paste with Ctrl+V)';
        
        container.appendChild(attachIcon);
        
        // Create preview container
        const previewContainer = document.createElement('div');
        previewContainer.className = 'pasted-images-container mt-2';
        container.appendChild(previewContainer);
        
        // Map textarea to file input
        this.textareaFileMap.set(textarea, fileInput);
        
        // Event handlers
        attachIcon.addEventListener('click', () => {
            fileInput.click();
        });
        
        fileInput.addEventListener('change', (e) => {
            this.handleFileInputChange(e, container);
            this.updateAttachmentIcon(attachIcon, fileInput);
        });
        
        // Add drag and drop to textarea
        this.addTextareaDragDrop(textarea, fileInput, container);
    }
    
    addDragDropHandlers(dropZone, input) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
        });
        
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.classList.add('dragover');
            });
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.classList.remove('dragover');
            });
        });
        
        dropZone.addEventListener('drop', (e) => {
            const files = Array.from(e.dataTransfer.files);
            this.handleFiles(files, input, dropZone);
        });
    }
    
    handleTextareaPaste(e, textarea) {
        const items = Array.from(e.clipboardData.items);
        const imageItems = items.filter(item => item.type.startsWith('image/'));
        
        if (imageItems.length === 0) {
            return;
        }
        
        const fileInput = this.textareaFileMap.get(textarea);
        if (!fileInput) {
            console.warn('No file input found for textarea');
            return;
        }
        
        // Visual feedback
        textarea.classList.add('paste-active');
        setTimeout(() => textarea.classList.remove('paste-active'), 1000);
        
        imageItems.forEach(item => {
            const file = item.getAsFile();
            if (file) {
                this.processClipboardFile(file, fileInput);
            }
        });
        
        this.showToast(`${imageItems.length} image(s) pasted successfully`, 'success');
    }
    
    addTextareaDragDrop(textarea, fileInput, container) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            textarea.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
        });
        
        ['dragenter', 'dragover'].forEach(eventName => {
            textarea.addEventListener(eventName, () => {
                textarea.classList.add('paste-active');
            });
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            textarea.addEventListener(eventName, () => {
                textarea.classList.remove('paste-active');
            });
        });
        
        textarea.addEventListener('drop', (e) => {
            const files = Array.from(e.dataTransfer.files);
            const imageFiles = files.filter(file => file.type.startsWith('image/'));
            
            if (imageFiles.length > 0) {
                imageFiles.forEach(file => {
                    if (this.validateFile(file)) {
                        this.addFileToInput(file, fileInput);
                        // Don't create preview here - let the change event handle it
                    }
                });
                
                this.updateAttachmentIcon(container.querySelector('.attachment-icon'), fileInput);
                this.showToast(`${imageFiles.length} image(s) added`, 'success');
            }
        });
    }
    
    updateAttachmentIcon(icon, fileInput) {
        const fileCount = fileInput.files.length;
        if (fileCount > 0) {
            icon.classList.add('has-files');
            icon.innerHTML = `<i class="fas fa-paperclip"></i><span style="position:absolute;top:-5px;right:-5px;background:#dc3545;color:white;border-radius:50%;width:16px;height:16px;font-size:10px;display:flex;align-items:center;justify-content:center;">${fileCount}</span>`;
            icon.title = `${fileCount} image(s) attached`;
        } else {
            icon.classList.remove('has-files');
            icon.innerHTML = '<i class="fas fa-paperclip"></i>';
            icon.title = 'Attach images (or paste with Ctrl+V)';
        }
    }
    
    processClipboardFile(file, input) {
        // Validate file
        if (!this.validateFile(file)) {
            return;
        }
        
        // Generate unique filename for pasted image
        const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
        const extension = this.getFileExtension(file.type);
        const filename = `pasted-image-${timestamp}${extension}`;
        
        // Create new file with proper name
        const renamedFile = new File([file], filename, { type: file.type });
        
        // Add to file input (this will trigger the change event which handles the preview)
        this.addFileToInput(renamedFile, input);
        
        // Don't create preview here - let the change event handle it to avoid duplicates
        
        this.showMessage(`Image pasted successfully: ${filename}`, 'success');
    }
    
    handleFiles(files, input, dropZone) {
        const imageFiles = files.filter(file => file.type.startsWith('image/'));
        
        if (imageFiles.length === 0) {
            this.showMessage('No image files found', 'error', dropZone);
            return;
        }
        
        imageFiles.forEach(file => {
            if (this.validateFile(file)) {
                this.addFileToInput(file, input);
                // Don't create preview here - let the change event handle it
            }
        });
        
        if (imageFiles.length > 0) {
            this.showMessage(`${imageFiles.length} image(s) added successfully`, 'success', dropZone);
        }
    }
    

    
    validateFile(file) {
        if (!this.options.allowedTypes.includes(file.type)) {
            this.showMessage(`File type ${file.type} not allowed`, 'error');
            return false;
        }
        
        if (file.size > this.options.maxFileSize) {
            const maxSizeMB = (this.options.maxFileSize / (1024 * 1024)).toFixed(1);
            this.showMessage(`File size exceeds ${maxSizeMB}MB limit`, 'error');
            return false;
        }
        
        return true;
    }
    
    addFileToInput(file, input) {
        // Create a new FileList with existing files plus the new one
        const dt = new DataTransfer();
        
        // Add existing files (check for duplicates by name and size)
        if (input.files) {
            Array.from(input.files).forEach(existingFile => {
                // Skip if we're trying to add a duplicate
                if (existingFile.name !== file.name || existingFile.size !== file.size) {
                    dt.items.add(existingFile);
                }
            });
        }
        
        // Add new file
        dt.items.add(file);
        
        // Update input
        input.files = dt.files;
        
        // Trigger change event
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    
    showImagePreview(file, input, container = null) {
        if (!container) {
            container = input.closest('.textarea-attachment-container') || input.parentNode;
        }
        
        const previewContainer = container.querySelector('.pasted-images-container');
        if (!previewContainer) {
            return;
        }
        
        const reader = new FileReader();
        reader.onload = (e) => {
            const preview = document.createElement('div');
            preview.className = 'pasted-image-preview';
            preview.innerHTML = `
                <img src="${e.target.result}" alt="${file.name}">
                <button type="button" class="remove-btn" title="Remove image">
                    <i class="fas fa-times"></i>
                </button>
                <div class="p-2">
                    <small class="text-muted">${file.name}</small><br>
                    <small class="text-muted">${this.formatFileSize(file.size)}</small>
                </div>
            `;
            
            // Add remove functionality
            preview.querySelector('.remove-btn').addEventListener('click', () => {
                this.removeFileFromInput(file, input);
                preview.remove();
                
                // Update attachment icon
                const attachIcon = container.querySelector('.attachment-icon');
                if (attachIcon) {
                    this.updateAttachmentIcon(attachIcon, input);
                }
            });
            
            previewContainer.appendChild(preview);
        };
        
        reader.readAsDataURL(file);
    }
    
    removeFileFromInput(fileToRemove, input) {
        const dt = new DataTransfer();
        
        Array.from(input.files).forEach(file => {
            if (file !== fileToRemove) {
                dt.items.add(file);
            }
        });
        
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    
    showMessage(message, type = 'info', container = null) {
        if (!container) {
            container = document.body;
        }
        
        const messageDiv = document.createElement('div');
        messageDiv.className = `paste-${type}`;
        messageDiv.textContent = message;
        
        if (container.querySelector('.paste-drop-zone')) {
            container.querySelector('.paste-drop-zone').appendChild(messageDiv);
        } else {
            container.appendChild(messageDiv);
        }
        
        // Remove message after 3 seconds
        setTimeout(() => {
            if (messageDiv.parentNode) {
                messageDiv.remove();
            }
        }, 3000);
    }
    
    getFileExtension(mimeType) {
        const extensions = {
            'image/jpeg': '.jpg',
            'image/jpg': '.jpg',
            'image/png': '.png',
            'image/gif': '.gif',
            'image/webp': '.webp'
        };
        
        return extensions[mimeType] || '.jpg';
    }
    
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    handleFileInputChange(e, container) {
        const files = Array.from(e.target.files);
        files.forEach(file => {
            this.showImagePreview(file, e.target, container);
        });
        
        // Update attachment icon if it exists
        const attachIcon = container.querySelector('.attachment-icon');
        if (attachIcon) {
            this.updateAttachmentIcon(attachIcon, e.target);
        }
    }
    
    showToast(message, type = 'success') {
        // Create toast notification
        const toast = document.createElement('div');
        toast.className = `alert alert-${type} alert-dismissible fade show`;
        toast.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        toast.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check' : 'exclamation-triangle'}"></i>
            ${message}
            <button type="button" class="close" data-dismiss="alert">
                <span>&times;</span>
            </button>
        `;
        
        document.body.appendChild(toast);
        
        // Auto remove after 3 seconds
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 3000);
    }
}

// Auto-initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize clipboard paste handler
    window.clipboardPasteHandler = new ClipboardPasteHandler({
        maxFileSize: 5 * 1024 * 1024, // 5MB
        allowedTypes: ['image/png', 'image/jpeg', 'image/jpg', 'image/gif'],
        quality: 0.8
    });
    
    console.log('Clipboard paste handler initialized');
});

// Re-initialize for dynamically added file inputs
function initializeClipboardPaste(textarea, existingFileInput) {
    if (window.clipboardPasteHandler && textarea && existingFileInput) {
        // Check if already initialized to prevent duplicates
        if (textarea.classList.contains('textarea-with-paste')) {
            return;
        }
        
        // Create container wrapper if it doesn't exist
        let container = textarea.closest('.textarea-attachment-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'textarea-attachment-container';
            textarea.parentNode.insertBefore(container, textarea);
            container.appendChild(textarea);
        }
        
        // Add classes to textarea
        textarea.classList.add('textarea-with-paste');
        
        // Create attachment icon
        const attachIcon = document.createElement('button');
        attachIcon.type = 'button';
        attachIcon.className = 'attachment-icon';
        attachIcon.innerHTML = '<i class="fas fa-paperclip"></i>';
        attachIcon.title = 'Attach images (or paste with Ctrl+V)';
        container.appendChild(attachIcon);
        
        // Create preview container
        const previewContainer = document.createElement('div');
        previewContainer.className = 'pasted-images-container mt-2';
        container.appendChild(previewContainer);
        
        // Map textarea to the existing file input
        window.clipboardPasteHandler.textareaFileMap.set(textarea, existingFileInput);
        
        // Event handlers
        attachIcon.addEventListener('click', () => {
            existingFileInput.click();
        });
        
        existingFileInput.addEventListener('change', (e) => {
            window.clipboardPasteHandler.handleFileInputChange(e, container);
        });
        
        // Add drag and drop to textarea
        window.clipboardPasteHandler.addTextareaDragDrop(textarea, existingFileInput, container);
        
        // Update attachment icon initially
        window.clipboardPasteHandler.updateAttachmentIcon(attachIcon, existingFileInput);
    }
}