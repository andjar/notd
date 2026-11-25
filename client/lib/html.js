// lit-html, for the core and for plugins. notd has no import map, so nothing imports
// a library by a bare name ('lit-html'): an inline import map needs a hash in the
// page's CSP, and that hash breaks as soon as the page's bytes change on the way
// (a Windows checkout with CRLF line endings), which stops every module. Import this
// file by its relative path instead; from a plugin's main.js that is
//   import { html, render } from '../../client/lib/html.js';
export { html, render, nothing, svg } from '../vendor/lit-html/lit-html.js';
export { unsafeHTML } from '../vendor/lit-html/directives/unsafe-html.js';
export { repeat } from '../vendor/lit-html/directives/repeat.js';
export { classMap } from '../vendor/lit-html/directives/class-map.js';
export { live } from '../vendor/lit-html/directives/live.js';
