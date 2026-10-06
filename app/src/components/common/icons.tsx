import type { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement> & { size?: number };

function Icon({ size = 20, children, ...rest }: IconProps & { children: React.ReactNode }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      {...rest}
    >
      {children}
    </svg>
  );
}

/** The dot of the LIVE badge in its ring; the caller colours it (text-live). */
export const LiveIcon = (p: IconProps) => (
  <Icon {...p}>
    <circle cx="12" cy="12" r="8.5" strokeOpacity="0.45" />
    <circle cx="12" cy="12" r="4" fill="currentColor" stroke="none" className="animate-pulse" />
  </Icon>
);
export const CalendarIcon = (p: IconProps) => (
  <Icon {...p}>
    <rect x="3" y="5" width="18" height="17" rx="2" />
    <path d="M7 2v6M17 2v6M3 11h18" />
  </Icon>
);
export const ChatIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M21 11.5a9 9 0 0 1-9 9 10 10 0 0 1-4-.8L3 22l1.7-5.2A9 9 0 1 1 21 11.5Z" />
  </Icon>
);
export const UserIcon = (p: IconProps) => (
  <Icon {...p}>
    <circle cx="12" cy="7" r="4" />
    <path d="M4 22v-2a8 8 0 0 1 16 0v2" />
  </Icon>
);
export const UsersIcon = (p: IconProps) => (
  <Icon {...p}>
    <circle cx="9" cy="7" r="4" />
    <path d="M2 22v-3a7 7 0 0 1 14 0v3M17 3a4 4 0 0 1 0 8M18 14a6 6 0 0 1 4 6v2" />
  </Icon>
);
export const RadioIcon = (p: IconProps) => (
  <Icon {...p}>
    <circle cx="12" cy="12" r="2" fill="currentColor" />
    <path d="M8.5 8.5a5 5 0 0 0 0 7M15.5 8.5a5 5 0 0 1 0 7M5.6 5.6a9 9 0 0 0 0 12.8M18.4 5.6a9 9 0 0 1 0 12.8" />
  </Icon>
);
export const ChevronIcon = ({ dir = 'down', ...p }: IconProps & { dir?: 'down' | 'up' | 'left' | 'right' }) => (
  <Icon {...p} style={{ transform: `rotate(${{ down: 0, left: 90, up: 180, right: -90 }[dir]}deg)` }}>
    <path d="m6 9 6 6 6-6" />
  </Icon>
);
export const ArrowRightIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M5 12h14M13 6l6 6-6 6" />
  </Icon>
);
export const HeartIcon = ({ filled, ...p }: IconProps & { filled?: boolean }) => (
  <Icon {...p} fill={filled ? 'currentColor' : 'none'}>
    <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z" />
  </Icon>
);
/** Praying hands, drawn as two mirrored palms (the design's symbol). */
export const PrayIcon = ({ filled, ...p }: IconProps & { filled?: boolean }) => (
  <Icon {...p} fill={filled ? 'currentColor' : 'none'}>
    <path d="M12 3v12m0-12c-2 0-2 5-3 8s-3 5-6 7l4 4 5-7m0-12c2 0 2 5 3 8s3 5 6 7l-4 4-5-7" />
  </Icon>
);
export const SmileIcon = (p: IconProps) => (
  <Icon {...p}>
    <circle cx="12" cy="12" r="9" />
    <path d="M8 14a4.5 4.5 0 0 0 8 0" />
    <circle cx="9" cy="9" r=".8" fill="currentColor" stroke="none" />
    <circle cx="15" cy="9" r=".8" fill="currentColor" stroke="none" />
  </Icon>
);
export const MusicIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M9 18V5l11-2v13M9 9l11-2" />
    <circle cx="6" cy="18" r="3" />
    <circle cx="17" cy="16" r="3" />
  </Icon>
);
export const MicIcon = (p: IconProps) => (
  <Icon {...p}>
    <rect x="9" y="2" width="6" height="13" rx="3" />
    <path d="M5 10v2a7 7 0 0 0 14 0v-2M12 19v3M8 22h8" />
  </Icon>
);
/** An open Bible: a video of a video program — a preaching, a testimony, a mission video, a film. */
export const BookIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M12 6.5C10.3 5 7.9 4.4 4 4.5v13c3.9-.1 6.3.5 8 2 1.7-1.5 4.1-2.1 8-2v-13c-3.9-.1-6.3.5-8 2Z" />
    <path d="M12 6.5v13M7 9.5h2M15 9.5h2M16 7.5v4" />
  </Icon>
);
export const RoomIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M7.5 17.5 4 20v-4.3A7.5 7.5 0 1 1 7.5 17.5Z" />
    <path d="M10 10h6M10 13h4" />
  </Icon>
);
export const CloseIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M6 6l12 12M18 6 6 18" />
  </Icon>
);
/** The big stage: four corners going out, and coming back in. */
export const ExpandIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M16 21h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3" />
  </Icon>
);
export const ShrinkIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M8 3v3a2 2 0 0 1-2 2H3M21 8h-3a2 2 0 0 1-2-2V3M16 21v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3" />
  </Icon>
);
export const PlayIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M7 4.5v15l12-7.5-12-7.5Z" fill="currentColor" />
  </Icon>
);
/** A website: a globe. */
export const GlobeIcon = (p: IconProps) => (
  <Icon {...p}>
    <circle cx="12" cy="12" r="9" />
    <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
  </Icon>
);
/** A link that leaves the app. */
export const ExternalIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" />
  </Icon>
);
export const PauseIcon = (p: IconProps) => (
  <Icon {...p}>
    <rect x="6.5" y="5" width="3.6" height="14" rx="1.2" fill="currentColor" stroke="none" />
    <rect x="13.9" y="5" width="3.6" height="14" rx="1.2" fill="currentColor" stroke="none" />
  </Icon>
);
export const VolumeIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M4 9.5v5h3.5L12 18V6L7.5 9.5H4Z" />
    <path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11" />
  </Icon>
);
export const ShieldIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6L12 3Z" />
  </Icon>
);
export const CheckIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="m5 12.5 4.5 4.5L19 7.5" />
  </Icon>
);
export const SendIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M4 12 20 4l-6.5 16-2.5-6.5L4 12Z" />
  </Icon>
);
/** "Remind me": filled while a reminder is set. */
export const BellIcon = ({ filled, ...p }: IconProps & { filled?: boolean }) => (
  <Icon {...p} fill={filled ? 'currentColor' : 'none'}>
    <path d="M6 9a6 6 0 0 1 12 0c0 5 2 6.5 2 6.5H4S6 14 6 9Z" />
    <path d="M10.3 19a1.9 1.9 0 0 0 3.4 0" />
  </Icon>
);
export const ShareIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M12 3v12M7.5 7.5 12 3l4.5 4.5" />
    <path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7" />
  </Icon>
);
export const FlagIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M5 21V4M5 4h11l-2 4 2 4H5" />
  </Icon>
);
export const RefreshIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M20 11a8 8 0 0 0-14.3-4.9L4 8M4 4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16M20 20v-4h-4" />
  </Icon>
);
export const SunIcon = (p: IconProps) => (
  <Icon {...p}>
    <circle cx="12" cy="12" r="4" />
    <path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4" />
  </Icon>
);

/** The menu's "Live": a ring around a dot (the caller colours it red). */
export const NavLiveIcon = (p: IconProps) => (
  <Icon {...p}>
    <circle cx="12" cy="12" r="3" />
    <circle cx="12" cy="12" r="9" />
  </Icon>
);
/** The mast above the dark theme's "Arche Radio". */
export const BroadcastIcon = (p: IconProps) => (
  <Icon {...p} overflow="visible">
    <circle cx="12" cy="11" r="2" fill="currentColor" stroke="none" />
    <path d="M12 13v9M8 7a6 6 0 0 0 0 8M16 7a6 6 0 0 1 0 8M5 4a10 10 0 0 0 0 14M19 4a10 10 0 0 1 0 14M2 1a14 14 0 0 0 0 20M22 1a14 14 0 0 1 0 20" />
  </Icon>
);
/** The level bars in front of "Now playing". */
export const LevelsIcon = (p: IconProps) => (
  <Icon {...p} strokeWidth={3} strokeLinecap="butt">
    <path d="M3 13v8M9 3v18M15 8v13M21 15v6" />
  </Icon>
);
/** The menu's "Moderation": a shield with a tick. */
export const ModerationIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M12 2 20 5v6c0 5-3.4 8.6-8 11-4.6-2.4-8-6-8-11V5l8-3Z" />
    <path d="m9 12 2 2 4-4" />
  </Icon>
);
