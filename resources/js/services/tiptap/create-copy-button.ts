interface CopyButtonOptions {
    title: string;
    onClick: () => void | Promise<void>;
}

export function createCopyButton({ title, onClick }: CopyButtonOptions): HTMLButtonElement {
    const button = document.createElement('button');
    button.type = 'button';
    button.title = title;
    button.setAttribute('aria-label', title);
    button.classList.add('h-4', 'w-4', 'focus:outline-none');
    button.innerHTML =
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';

    button.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        void onClick();
    });

    return button;
}
