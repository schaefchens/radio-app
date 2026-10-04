#!/usr/bin/env node
/**
 * One version for both store apps.
 *
 * Usage: node scripts/native/version.mjs [x.y.z]
 *   with x.y.z: the version the stores show (Android versionName, iOS
 *               MARKETING_VERSION), and the next build number;
 *   without:    only the next build number (uploading the same version again).
 *
 * The build number is one counter for both stores (Android versionCode, iOS
 * CURRENT_PROJECT_VERSION): each store refuses a number it has seen before.
 * The script refuses to write unless it finds exactly the lines it expects,
 * so a changed project file cannot be half-edited.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const GRADLE = path.join(ROOT, 'app/android/app/build.gradle');
const PBXPROJ = path.join(ROOT, 'app/ios/App/App.xcodeproj/project.pbxproj');

const version = process.argv[2];
if (version !== undefined && !/^\d+\.\d+\.\d+$/.test(version)) {
  console.error(`error: "${version}" is not a version like 1.2.3`);
  process.exit(1);
}

let gradle = readFileSync(GRADLE, 'utf8');
let pbx = readFileSync(PBXPROJ, 'utf8');

/** Every match of a pattern, insisting on how many there are. */
function matches(text, re, count, where) {
  const found = [...text.matchAll(re)];
  if (found.length !== count) {
    console.error(`error: expected ${count} × ${re} in ${where}, found ${found.length}`);
    process.exit(1);
  }
  return found;
}

const codes = matches(gradle, /^(\s*)versionCode (\d+)$/gm, 1, 'build.gradle');
const builds = matches(pbx, /CURRENT_PROJECT_VERSION = (\d+);/g, 2, 'project.pbxproj');
matches(gradle, /^(\s*)versionName "[^"]*"$/gm, 1, 'build.gradle');
matches(pbx, /MARKETING_VERSION = [^;]+;/g, 2, 'project.pbxproj');

const next = Math.max(Number(codes[0][2]), ...builds.map((m) => Number(m[1]))) + 1;
gradle = gradle.replace(/^(\s*)versionCode \d+$/m, `$1versionCode ${next}`);
pbx = pbx.replace(/CURRENT_PROJECT_VERSION = \d+;/g, `CURRENT_PROJECT_VERSION = ${next};`);
if (version) {
  gradle = gradle.replace(/^(\s*)versionName "[^"]*"$/m, `$1versionName "${version}"`);
  pbx = pbx.replace(/MARKETING_VERSION = [^;]+;/g, `MARKETING_VERSION = ${version};`);
}

writeFileSync(GRADLE, gradle);
writeFileSync(PBXPROJ, pbx);
const shown = version ?? /versionName "([^"]*)"/.exec(gradle)?.[1];
console.log(`Arche Radio ${shown} (build ${next}) — commit: "Release the apps ${shown} (build ${next})"`);
