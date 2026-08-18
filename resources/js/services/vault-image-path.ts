function decodeVaultPath(path: string): string {
    try {
        return decodeURI(path);
    } catch {
        return path;
    }
}

function normalizeVaultPath(path: string): string {
    const parts: string[] = [];

    for (const part of path.split('/')) {
        if (part === '' || part === '.') {
            continue;
        }

        if (part === '..') {
            parts.pop();
            continue;
        }

        parts.push(part);
    }

    return `/${parts.join('/')}`;
}

function localPathFromSource(source: string, currentOrigin: string): string | null {
    if (source.startsWith('//')) {
        return null;
    }

    if (/^[a-z][a-z\d+.-]*:/i.test(source)) {
        try {
            const url = new URL(source);

            if (url.origin !== currentOrigin || !/^\/files\/\d+$/.test(url.pathname)) {
                return null;
            }

            return url.searchParams.get('path');
        } catch {
            return null;
        }
    }

    if (/^\/files\/\d+(?:\?|$)/.test(source)) {
        try {
            return new URL(source, currentOrigin).searchParams.get('path');
        } catch {
            return null;
        }
    }

    return source;
}

export function resolveVaultImagePath(
    source: string | null | undefined,
    noteFullPath: string,
    currentOrigin: string = globalThis.location?.origin ?? 'http://localhost'
): string | null {
    if (!source) {
        return null;
    }

    const localPath = localPathFromSource(source, currentOrigin);

    if (!localPath) {
        return null;
    }

    const decodedPath = decodeVaultPath(localPath);

    if (decodedPath.startsWith('/')) {
        return normalizeVaultPath(decodedPath);
    }

    const noteDirectory = noteFullPath.slice(0, Math.max(0, noteFullPath.lastIndexOf('/')));

    return normalizeVaultPath(`${noteDirectory}/${decodedPath}`);
}
