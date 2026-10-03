'use strict';
// phone-dial-logic.js (pure, DOM-free) -- driven directly under Node, plus a
// cross-check of its failure descriptions against the REAL vendored JsSIP's
// cause strings (so an upgrade that renames a cause cannot silently turn a
// helpful message back into "Call ended: <raw cause>").
var fs = require('fs');
var path = require('path');
var root = process.argv[2];
var R = (function () {
    var out = [];
    return { check: function (n, c, d) { out.push((c ? 'PASS|' : 'FAIL|') + n + '|' + (d === undefined ? '' : d)); }, out: out };
})();

var L = require(path.join(root, 'assets/js/phone-dial-logic.js'));

// ── Where it registers ────────────────────────────────────────────────
R.check('pageNameFromPath: a nested install path', L.pageNameFromPath('/newui/Console.PHP') === 'console.php');
R.check('pageNameFromPath: a root-level page', L.pageNameFromPath('/phone.php') === 'phone.php');
R.check('pageNameFromPath: a directory index', L.pageNameFromPath('/newui/') === 'index.php' && L.pageNameFromPath('') === 'index.php');
R.check('pageNameFromPath: query and fragment never leak into the name', L.pageNameFromPath('/x/console.php?a=1#b') === 'console.php');
R.check('normalizeScope: only every_page is "every_page"; everything else is the safe default',
    L.normalizeScope('every_page') === 'every_page' && L.normalizeScope('phone_page') === 'phone_page'
    && L.normalizeScope('') === 'phone_page' && L.normalizeScope(null) === 'phone_page' && L.normalizeScope('EVERY_PAGE') === 'phone_page');
R.check('shouldRegister(phone_page): console.php and phone.php only',
    L.shouldRegister('phone_page', 'console.php', false) && L.shouldRegister('phone_page', 'phone.php', false)
    && !L.shouldRegister('phone_page', 'index.php', false) && !L.shouldRegister('phone_page', 'incident-detail.php', false));
R.check('shouldRegister(every_page): everywhere', L.shouldRegister('every_page', 'index.php', false) && L.shouldRegister('every_page', 'units.php', false));
R.check('shouldRegister: the standalone window always registers', L.shouldRegister('phone_page', 'whatever.php', true));

// ── Token ─────────────────────────────────────────────────────────────
R.check('resolveToken: trims, and survives a throwing or missing getter',
    L.resolveToken(function () { return '  abc '; }) === 'abc'
    && L.resolveToken(function () { throw new Error('private window'); }) === ''
    && L.resolveToken(undefined) === '' && L.resolveToken(function () { return null; }) === '');

// ── Election ──────────────────────────────────────────────────────────
var now = 1000000;
var fresh = { id: 'a', at: now - 1000, standalone: false };
var freshStandalone = { id: 'a', at: now - 1000, standalone: true };
var stale = { id: 'a', at: now - L.REGISTRANT_STALE_MS - 1, standalone: true };
R.check('registrantDecide: nobody registered -> register', L.registrantDecide(null, 'me', false, now) === 'register');
R.check('registrantDecide: my own record -> register (refresh)', L.registrantDecide({ id: 'me', at: now, standalone: false }, 'me', false, now) === 'register');
R.check('registrantDecide: a fresh other window -> yield', L.registrantDecide(fresh, 'me', false, now) === 'yield');
R.check('registrantDecide: a STALE other window -> register', L.registrantDecide(stale, 'me', false, now) === 'register');
R.check('registrantDecide: the standalone window takes over from an ordinary one', L.registrantDecide(fresh, 'me', true, now) === 'register');
R.check('registrantDecide: an ordinary window does NOT take over from the standalone one', L.registrantDecide(freshStandalone, 'me', false, now) === 'yield');
R.check('registrantDecide: two standalone windows -> the later one yields (no flapping)', L.registrantDecide(freshStandalone, 'me', true, now) === 'yield');
R.check('parseRegistrant: round trip, and garbage is "no registrant", never a throw',
    L.parseRegistrant(L.serializeRegistrant('x', true, 5)).id === 'x' && L.parseRegistrant('{not json') === null
    && L.parseRegistrant('') === null && L.parseRegistrant('{"id":5,"at":"x"}') === null);

// ── Linkedid header ───────────────────────────────────────────────────
function req(v) { return { getHeader: function (n) { return n === 'X-Call-Linkedid' ? v : undefined; } }; }
R.check('linkedIdFromRequest: a normal Asterisk Linkedid', L.linkedIdFromRequest(req('1759421234.12')) === '1759421234.12');
R.check('linkedIdFromRequest: surrounding whitespace is trimmed', L.linkedIdFromRequest(req('  1759421234.12 ')) === '1759421234.12');
R.check('linkedIdFromRequest: absent / empty -> ""', L.linkedIdFromRequest(req(undefined)) === '' && L.linkedIdFromRequest(req('')) === '' && L.linkedIdFromRequest({}) === '' && L.linkedIdFromRequest(null) === '');
R.check('linkedIdFromRequest: a hostile or oversized header is dropped, not forwarded',
    L.linkedIdFromRequest(req('1;DROP TABLE x')) === '' && L.linkedIdFromRequest(req('<script>')) === ''
    && L.linkedIdFromRequest(req(new Array(80).join('9'))) === '' && L.linkedIdFromRequest(req('a b')) === '');
R.check('linkedIdFromRequest: a getHeader that throws is survivable',
    L.linkedIdFromRequest({ getHeader: function () { throw new Error('x'); } }) === '');
// The event is the documented way to reach the INVITE: JsSIP's newRTCSession event
// carries {originator, session, request}; an RTCSession does not publish its request.
R.check('linkedIdFromEvent: the id comes from the request on the event (the real JsSIP shape)',
    L.linkedIdFromEvent({ session: {}, originator: 'remote', request: req('1759421234.12') }) === '1759421234.12');
R.check('linkedIdFromEvent: a session that happens to expose its request is a fallback only',
    L.linkedIdFromEvent({ session: { request: req('55.1') } }) === '55.1' && L.linkedIdFromEvent({ session: { _request: req('56.2') } }) === '56.2');
R.check('linkedIdFromEvent: nothing anywhere -> "" and never a throw',
    L.linkedIdFromEvent({}) === '' && L.linkedIdFromEvent(null) === '' && L.linkedIdFromEvent({ session: {} }) === '');
R.check('...and the event wins over a session property', L.linkedIdFromEvent({ request: req('1.1'), session: { request: req('2.2') } }) === '1.1');

// ── Words ─────────────────────────────────────────────────────────────
R.check('certUrl maps the WSS address to the https address to trust once',
    L.certUrl('wss://pbx.example:8089/ws') === 'https://pbx.example:8089/' && L.certUrl('wss://h/ws/') === 'https://h/');
R.check('describeMicBlocker: insecure page, no media devices, and the all-clear',
    /secure page/.test(L.describeMicBlocker(false, true)) && /microphone/.test(L.describeMicBlocker(true, false))
    && L.describeMicBlocker(true, true) === '' && L.describeMicBlocker(undefined, true) === '');

// ── The real JsSIP's cause strings ────────────────────────────────────
global.window = global;
Object.defineProperty(global, 'navigator', { value: { userAgent: 'node' }, configurable: true, writable: true });
global.document = { createElement: function () { return {}; }, getElementsByTagName: function () { return []; } };
var causes = null;
try {
    var bundle = path.join(root, 'assets/vendor/jssip/jssip-3.10.1.min.js');
    // eslint-disable-next-line no-eval
    (0, eval)(fs.readFileSync(bundle, 'utf8'));
    causes = global.JsSIP.C.causes;
} catch (e) {
    R.check('the vendored JsSIP bundle loads under Node', false, String(e && e.message));
}
if (causes) {
    var values = Object.keys(causes).map(function (k) { return causes[k]; });
    var handledCall = ['User Denied Media Access', 'WebRTC Error', 'Bad Media Description', 'Incompatible SDP', 'Busy', 'Rejected',
        'Not Found', 'Unavailable', 'No Answer', 'Request Timeout', 'Connection Error', 'Canceled', 'Terminated', 'RTP Timeout'];
    var handledReg = ['Authentication Error', 'Rejected', 'Connection Error', 'Request Timeout', 'Not Found', 'Unavailable'];
    var missing = handledCall.concat(handledReg).filter(function (c) { return values.indexOf(c) === -1; });
    R.check('every cause string the descriptions key on exists in the vendored JsSIP', missing.length === 0, 'missing=' + missing.join(','));
    var generic = handledCall.filter(function (c) { return /^Call ended: /.test(L.describeCallFailure(c)); });
    R.check('...and none of them falls through to the generic "Call ended: <cause>" text', generic.length === 0, generic.join(','));
    var genericReg = handledReg.filter(function (c) { return /^Registration failed \(/.test(L.describeRegistrationFailure(c, 'wss://h/ws')); });
    R.check('...nor does any registration cause', genericReg.length === 0, genericReg.join(','));
    R.check('an unlisted cause still produces a sentence naming the cause (never blank)',
        /Internal Error/.test(L.describeCallFailure('Internal Error')) && /unknown reason/.test(L.describeRegistrationFailure('', 'wss://h/ws')));
    R.check('a denied microphone is described as such', /Microphone blocked/.test(L.describeCallFailure(causes.USER_DENIED_MEDIA_ACCESS)));
}

console.log(R.out.join('\n'));
process.exit(0);
