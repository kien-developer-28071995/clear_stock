import { useTranslation } from 'react-i18next';
import { IMPORT_FIELDS, type ImportField, type ImportMapping } from '@/features/imports/types';

/** Which column of the file holds what. Pre-filled by the server's guess; any change re-runs the preview. */
export function ColumnMapping({ columns, mapping, onChange }: { columns: string[]; mapping: ImportMapping; onChange: (m: ImportMapping) => void }) {
    const { t } = useTranslation();

    return (
        <s-grid gridTemplateColumns="@container (inline-size > 500px) 1fr 1fr 1fr, 1fr" gap="base">
            {IMPORT_FIELDS.map((field: ImportField) => (
                <s-select
                    key={field}
                    label={t(`import.fields.${field}`)}
                    value={mapping[field] ?? ''}
                    onChange={(e) => onChange({ ...mapping, [field]: e.currentTarget.value || null })}
                >
                    <s-option value="">{t('import.notInFile')}</s-option>
                    {columns.map((c) => (
                        <s-option key={c} value={c}>{c}</s-option>
                    ))}
                </s-select>
            ))}
        </s-grid>
    );
}
