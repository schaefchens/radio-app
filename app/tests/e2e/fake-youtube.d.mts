export interface FakeVideo {
  id: string;
  title: string;
  channel: string;
  duration: string;
  embeddable: boolean;
  privacy: string;
  ageRestricted: boolean;
}

export declare const VIDEOS: FakeVideo[];
export declare const LIBRARY_IDS: string[];
