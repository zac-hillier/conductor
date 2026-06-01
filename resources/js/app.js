import Sortable from 'sortablejs';

// Wire SortableJS into each board column. Columns are marked with
// data-board-column and carry their target status in data-status.
// Dropping a card calls the Board component's moveTask action.
function initBoardSortable() {
    document.querySelectorAll('[data-board-column]').forEach((column) => {
        if (column.sortableInstance) {
            return;
        }

        column.sortableInstance = Sortable.create(column, {
            group: 'board',
            animation: 150,
            draggable: '[data-task-id]',
            ghostClass: 'opacity-50',
            onEnd(event) {
                const toStatus = event.to.dataset.status;
                const taskId = Number(event.item.dataset.taskId);

                if (!toStatus || !taskId) {
                    return;
                }

                const root = event.to.closest('[wire\\:id]');

                if (!root) {
                    return;
                }

                window.Livewire.find(root.getAttribute('wire:id'))
                    .call('moveTask', taskId, toStatus);
            },
        });
    });
}

document.addEventListener('livewire:navigated', initBoardSortable);
document.addEventListener('livewire:init', () => {
    initBoardSortable();
    window.Livewire.hook('morph.updated', initBoardSortable);
});
