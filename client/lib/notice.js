// Short messages in the bar above the page, where the save status is: what an
// upload is doing, why a file was refused. A message stays a few seconds (an
// error a little longer, a sticky one until the next), then "Saved" is back.
//
//   notice('Uploading photo.jpg…', { sticky: true });
//   notice('photo.heic was not attached: …', { error: true });

export function notice(text, { error = false, sticky = false } = {}) {
  document.dispatchEvent(new CustomEvent('notd:notice', { detail: { text, error, sticky } }));
}
