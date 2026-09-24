import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { countryName } from '@/lib/format';
import { COUNTRY_CODES } from './countries';

/** Only the country is ever shown next to a name — never a city from the device. */
export function CountrySelect({ value, onChange, id }: { value: string; onChange: (v: string) => void; id?: string }) {
  const { i18n } = useTranslation();
  const lang = i18n.language === 'de' ? 'de' : 'en';
  const options = useMemo(
    () => COUNTRY_CODES.map((c) => ({ code: c, name: countryName(c, lang) })).sort((a, b) => a.name.localeCompare(b.name, lang)),
    [lang],
  );
  return (
    <select id={id} className="field" value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">—</option>
      {options.map((o) => (
        <option key={o.code} value={o.code}>
          {o.name}
        </option>
      ))}
    </select>
  );
}
