export function LoadingPage({ heading }: { heading: string }) {
    return (
        <s-page heading={heading}>
            <s-section>
                <s-stack direction="inline" gap="small-200" alignItems="center">
                    <s-spinner accessibilityLabel="Loading" size="base" />
                    <s-text color="subdued">Loading…</s-text>
                </s-stack>
            </s-section>
        </s-page>
    );
}
