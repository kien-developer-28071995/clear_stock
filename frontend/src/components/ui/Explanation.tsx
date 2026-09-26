/** Plain-language "why this number" sentences produced by the backend formatter. */
export function Explanation({ sentences, limit }: { sentences: string[]; limit?: number }) {
    const shown = limit ? sentences.slice(0, limit) : sentences;
    return (
        <s-unordered-list>
            {shown.map((sentence) => (
                <s-list-item key={sentence}>{sentence}</s-list-item>
            ))}
        </s-unordered-list>
    );
}
