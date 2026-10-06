import { useEffect, useState } from 'react';

/** True on a phone-width screen (tables turn into lists there: show fewer fields per row). */
export function useIsNarrow(maxWidth = 490): boolean {
    const query = `(max-width: ${maxWidth}px)`;
    const [narrow, setNarrow] = useState(() => window.matchMedia(query).matches);

    useEffect(() => {
        const media = window.matchMedia(query);
        const update = () => setNarrow(media.matches);
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, [query]);

    return narrow;
}
