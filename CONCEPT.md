# ARCHE – Concept Overview

## Core Idea

A community-driven Christian live radio experience built as a PWA.

Everyone listens to and watches the same program at roughly the same time. Music and music videos are primarily delivered through embedded YouTube players.

## Live Program

A central schedule defines what is currently on air.

Possible program elements:

* Songs
* AI moderation
* Listener stories
* Prayer requests
* Jingles
* Themed sessions
* Moments of silence
* Short community messages

If there are not enough submissions, the system automatically fills the schedule from a pool of previously approved content.

## AI Radio Host

An AI host creates short transitions and announcements between program elements.

Example:

> “Jenny from Munich requested this song. She got married today — this one is for you.”

The host can react to listener submissions, themes, time of day, and previous program elements.

## Multilingual Radio

The program itself remains globally synchronized.

AI moderation can be generated in multiple languages. Each listener hears the same segment in their preferred language.
We know what users are online and their language. per default we do only en/de defaulting to en if its not german for now.

## Song Requests

Listeners can submit YouTube links.

The system automatically checks:

* Valid YouTube link
* Video duration
* Content
* Christian relevance
* Current theme relevance
* Safety and moderation rules

Accepted songs enter the scheduling pool. Uncertain submissions are rejected.

## Listener Contributions

Listeners can record short audio contributions, such as:

* Personal stories
* Thoughts
* Prayer requests
* Greetings
* Testimonies
* Encouragement

Audio is transcribed and automatically moderated before it can enter the program.

## Radio Stage

During songs, the YouTube video can be the main visual element.

Between songs, the app can switch to its own visual stage for:

* AI moderation
* Listener stories
* Prayer moments
* Community highlights
* Announcements
* Visual interludes

## Chat & Streams

Listeners can participate in smaller real-time community rooms.

They can:

* Chat
* React
* Like messages
* Share thoughts
* Suggest songs
* Submit contributions

Instead of one global chat with millions of messages, listeners are distributed across manageable rooms.

## Local & Global Community

Rooms can consider language and geographic proximity.

At the same time, selected messages can travel upward through the system and appear as global community highlights.

This creates both **local connection** and a sense of a **worldwide Christian community**.

## Technical Architecture

The PWA is largely offline-first.

Static components include:

* App shell
* Program schedules
* Metadata
* AI-generated moderation audio
* Jingles and radio elements

These can be cached and distributed through a CDN.

YouTube handles the heavy video traffic.

The backend mainly handles:

* User accounts
* Submissions
* AI moderation
* Scheduling
* Chat
* Reactions
* Real-time community features

## Programs and Plan
a non ai will generate plans for like a casual day, with time slots where programs run.
a program is abstract and defines what is allowed. but also its visible to users. a program might allow for certain user submissions, like prayers etc.
if we have a program slotted in like Prayer 7-8 daily it will have a certain setting and things allowed. the ai makes the moderation within the program and can decide things to show and play.
there might be a program for worship music going on for hours. users might submit own songs/videos link.
so the day has a plan. but there might be also a plan for the week. or daily plans like monday do these programs and tuesdays those. and special day plans like christmas and eastern.
its part of the app that restricted moderation users might create and edit plans.
normal users can show in nice diagram the daily or week plan, and in archive check in in previous days like listening to yesterdays program.
the plans show only structure and theme but not concrete songs or content, thats the surprise element. it might show previous songs and content though.

## User comments
users might in optional chat system post in their respective room. the main screen of the app will not show chat but a few messages like 1-3 coming in and going out. the user might react to those. those reactions might influence how relevant that comment becomes so it might be considered by ai host moderator to show as fly ins. 

## multiple channels
- the app has one main channel its active by default
- the app also has 0 or more optional channels to choose from
- the main screen shows info according to the channel the user is in.

## UI Main Screen
- should show player stage on top
- should show a channel selection, defaulting to main
- should show program title and subheader and live icon and numbers of live listeners above or below the stage
- should show 1-3 community voices with 2 big react buttons, a heart and a pray emoji and a smaller smile button to choose more reactions.
- interacted voices disappear
- tucked to the bottom should be the submission buttons available and the program schedule on a bottom sheet
- nav: home, schedule, chat, profile

## Principle

**Christ Community Radio.**
