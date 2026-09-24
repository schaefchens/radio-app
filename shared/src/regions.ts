/**
 * Chat rooms are grouped by channel, language and a coarse region, so a room
 * is local enough to feel like a neighbourhood and still large enough to be
 * alive. The region is derived from the listener's country (profile, or a
 * guess from the browser timezone) — never from IP geolocation.
 */

export const REGIONS = ['dach', 'europe', 'americas', 'africa', 'asia', 'oceania', 'world'] as const;
export type Region = (typeof REGIONS)[number];

const BY_COUNTRY: Record<string, Region> = {};
const assign = (region: Region, codes: string): void => {
  for (const c of codes.split(' ')) BY_COUNTRY[c] = region;
};

assign('dach', 'DE AT CH LI');
assign(
  'europe',
  'AD AL BA BE BG BY CY CZ DK EE ES FI FO FR GB GI GR HR HU IE IM IS IT LT LU LV MC MD ME MK MT NL NO PL PT RO RS RU SE SI SK SM UA VA XK',
);
assign(
  'americas',
  'AG AR BB BO BR BS BZ CA CL CO CR CU DM DO EC GD GT GY HN HT JM KN LC MX NI PA PE PR PY SR SV TT US UY VC VE',
);
assign(
  'africa',
  'AO BF BI BJ BW CD CF CG CI CM CV DJ DZ EG ER ET GA GH GM GN GQ GW KE KM LR LS LY MA MG ML MR MU MW MZ NA NE NG RW SC SD SL SN SO SS ST SZ TD TG TN TZ UG ZA ZM ZW',
);
assign(
  'asia',
  'AE AF AM AZ BD BH BN BT CN GE HK ID IL IN IQ IR JO JP KG KH KP KR KW KZ LA LB LK MM MN MO MV MY NP OM PH PK PS QA SA SG SY TH TJ TL TM TR TW UZ VN YE',
);
assign('oceania', 'AU FJ FM KI MH NR NZ PG PW SB TO TV VU WS');

export function regionOf(country: string | null | undefined): Region {
  return BY_COUNTRY[(country ?? '').toUpperCase()] ?? 'world';
}
