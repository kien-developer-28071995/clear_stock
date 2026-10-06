import { useState, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import type { ModalElement } from '@/hooks/useModal';
import { ApiError, errorMessage, fieldError } from '@/lib/http';
import { pickVariants, type PickedVariant } from '@/lib/resourcePicker';
import { useSaveBundle } from '@/features/settings/hooks/useSettings';

interface Props {
    modalRef: RefObject<ModalElement | null>;
    onDone: () => void;
}

/** Define "1 bundle uses N of each component" with Shopify's product picker. */
export function BundleModal({ modalRef, onDone }: Props) {
    const { t } = useTranslation();
    const save = useSaveBundle();
    const [bundle, setBundle] = useState<PickedVariant | null>(null);
    const [components, setComponents] = useState<(PickedVariant & { quantity: number })[]>([]);

    const pickBundle = async () => {
        const [picked] = await pickVariants();
        if (picked) setBundle(picked);
    };
    const pickComponents = async () => {
        const picked = await pickVariants({ multiple: true, selected: components.map((c) => c.gid) });
        if (picked.length === 0) return;
        setComponents(picked.map((p) => ({ ...p, quantity: components.find((c) => c.gid === p.gid)?.quantity ?? 1 })));
    };
    const submit = () =>
        bundle &&
        save.mutate(
            { bundle: bundle.gid, components: components.map((c) => ({ variant: c.gid, quantity: c.quantity })) },
            {
                onSuccess: () => {
                    shopify.toast.show(t('bundles.saved'));
                    setBundle(null);
                    setComponents([]);
                    onDone();
                },
            },
        );

    const generalError = save.error instanceof ApiError && save.error.status !== 422 ? errorMessage(save.error) : null;

    return (
        <s-modal ref={modalRef} id="bundle-modal" heading={t('bundles.add')}>
            <s-stack gap="base">
                <s-paragraph>{t('bundles.modalIntro')}</s-paragraph>
                {generalError && <s-banner tone="critical">{generalError}</s-banner>}
                <s-stack gap="small-200">
                    <s-text type="strong">{t('bundles.bundleProduct')}</s-text>
                    <s-stack direction="inline" gap="small-200" alignItems="center">
                        <s-text>{bundle?.name ?? t('bundles.noneSelected')}</s-text>
                        <s-button onClick={pickBundle}>{bundle ? t('bundles.change') : t('bundles.selectProduct')}</s-button>
                    </s-stack>
                    {fieldError(save.error, 'bundle') && <s-text tone="critical">{fieldError(save.error, 'bundle')}</s-text>}
                </s-stack>
                <s-stack gap="small-200">
                    <s-text type="strong">{t('bundles.contains')}</s-text>
                    {components.map((c, i) => (
                        <s-grid key={c.gid} gridTemplateColumns="1fr 120px" gap="small-200" alignItems="center">
                            <s-text>{c.name}</s-text>
                            <s-number-field
                                label={t('bundles.quantity')}
                                labelAccessibilityVisibility="exclusive"
                                min={1}
                                value={String(c.quantity)}
                                error={fieldError(save.error, `components.${i}.variant`) ?? fieldError(save.error, `components.${i}.quantity`)}
                                onInput={(e) =>
                                    setComponents(components.map((x) => (x.gid === c.gid ? { ...x, quantity: Number(e.currentTarget.value) || 1 } : x)))
                                }
                            />
                        </s-grid>
                    ))}
                    <s-stack direction="inline">
                        <s-button onClick={pickComponents}>{components.length ? t('bundles.changeProducts') : t('bundles.selectProducts')}</s-button>
                    </s-stack>
                </s-stack>
            </s-stack>
            <s-button
                slot="primary-action"
                variant="primary"
                onClick={submit}
                loading={save.isPending || undefined}
                disabled={!bundle || components.length === 0 || undefined}
            >
                {t('bundles.save')}
            </s-button>
            <s-button slot="secondary-actions" commandFor="bundle-modal" command="--hide">
                {t('common.cancel')}
            </s-button>
        </s-modal>
    );
}
