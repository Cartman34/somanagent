/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import Modal from './Modal'

interface ConfirmDialogProps {
  open: boolean
  onClose: () => void
  onConfirm: () => void
  title?: string
  message: string
  confirmLabel?: string
  cancelLabel?: string
  loadingLabel?: string
  loading?: boolean
}

/**
 * Displays a small confirmation modal for destructive or irreversible actions.
 */
export default function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  title = 'Confirm deletion',
  message,
  confirmLabel = 'Delete',
  cancelLabel = 'Cancel',
  loadingLabel,
  loading = false,
}: ConfirmDialogProps) {
  return (
    <Modal open={open} onClose={onClose} title={title} size="sm">
      <p className="text-sm text-gray-600 mb-6">{message}</p>
      <div className="flex justify-end gap-3">
        <button onClick={onClose} className="btn-secondary" disabled={loading}>
          {cancelLabel}
        </button>
        <button onClick={onConfirm} className="btn-danger" disabled={loading}>
          {loading ? (loadingLabel ?? confirmLabel) : confirmLabel}
        </button>
      </div>
    </Modal>
  )
}
