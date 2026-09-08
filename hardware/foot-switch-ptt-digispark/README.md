# DIY USB Foot-Switch PTT (Digispark / ATtiny85)

A build guide for a hands-free physical push-to-talk pedal for the
TicketsCAD Communications Console (Phase 152), using a Digispark
(ATtiny85) board — roughly $2-5 in parts plus any momentary foot switch
you already have or can source.

The console's own `assets/js/console-hid.js` polls the browser's
[Gamepad API](https://developer.mozilla.org/en-US/docs/Web/API/Gamepad_API)
for `buttons[0].pressed` on whichever channel strip is currently selected.
This build makes the Digispark present as a real, standards-compliant USB
HID gamepad with one button — no drivers, no companion software running
on the dispatch PC, recognized identically by Chrome/Firefox/Edge on
Windows, macOS, and Linux the moment it's plugged in (after its normal
boot delay — see below).

If you'd rather not build anything, an off-the-shelf option is also
supported — see `docs/COMMS-CONSOLE-GUIDE.md` section 8 and
`docs/AUDIO-MATRIX-SETUP.md`'s cross-references for a purchasable
programmable USB foot switch. This directory is for the DIY route.

## Why a real gamepad, not a keyboard-emulating pedal

`console-hid.js` also accepts a keyboard fallback (the backtick/grave
key), for pedals that emulate a keystroke instead. But that path
deliberately **ignores keypresses while focus is inside a text field**
(an input, textarea, select, or contenteditable element) — otherwise
typing a literal backtick character into, say, an incident note would
accidentally key up a radio channel. A real Gamepad API button has no
such filter: it fires from anywhere on the page, which is what you
actually want from a foot pedal a dispatcher might press while their
cursor is sitting in a notes field. That's the whole reason this build
targets the Gamepad path rather than the (also supported, and easier)
keyboard-emulation path.

## Parts list

| Part | Notes |
|---|---|
| Digispark (ATtiny85) board, any revision | Widely sold as "Digispark Kickstarter" or "Digispark clone" on Amazon/AliExpress/eBay, ~$2-5 |
| A normally-open momentary foot switch or pedal | Any SPST momentary switch works — a sealed rubber "arcade button"-style foot switch is a comfortable, durable choice; even a cheap doorbell-style push button in a project box works for testing |
| 2 wires + solder or a small terminal block | To connect the switch to the board |
| Small enclosure (optional) | A project box or 3D-printed case to keep the bare board protected once it's built |

No resistors needed — the sketch uses the ATtiny85's internal pull-up.

## Wiring

Connect the two leads of the momentary switch to:

- **P2** on the Digispark
- **GND** on the Digispark

That's it — one switch, two wires, no other components.

**Do not wire anything to P3 or P4.** Those are the Digispark's USB
D-/D+ data lines, physically connected to the USB port. The firmware
depends on them for USB communication (the ATtiny85 has no real USB
hardware — this whole approach relies on a software/bit-banged USB
stack, V-USB, that needs those two lines undisturbed). P1 is also usable
but is wired to the onboard LED on most boards, and P5 is the physical
reset pin — reassigning it as GPIO needs a fuse change that can only be
undone with a high-voltage programmer if something goes wrong. P2 avoids
all of that.

## Flashing the sketch

1. **Arduino IDE → File → Preferences → Additional Boards Manager URLs**,
   add:
   ```
   https://raw.githubusercontent.com/digistump/arduino-boards-index/master/package_digistump_index.json
   ```
2. **Tools → Board → Boards Manager**, search "Digistump", install
   "Digistump AVR Boards". This also installs the `DigiJoystick` library
   used by `foot-switch-ptt.ino` — nothing else to install.
3. **Tools → Board**, select "Digispark (Default - 16.5mhz)".
4. Open `foot-switch-ptt.ino` in the Arduino IDE.
5. Click **Upload**. The IDE will prompt you to plug the Digispark into a
   USB port *after* clicking Upload, not before — it's watching for the
   bootloader to appear. Plug it in when prompted; the upload completes
   in a few seconds.

## Two quirks of this platform (not bugs in the sketch)

- **A few seconds' delay every time you plug it in.** The Digispark's
  bootloader (Micronucleus) always waits several seconds after power-up,
  listening for a new firmware upload, before handing off to the
  installed sketch — so the pedal won't be recognized by the OS/browser
  instantly on plug-in. This is normal, and irrelevant once it's plugged
  into a workstation and left there.
- **The sketch never uses the plain Arduino `delay()`.** Because USB is
  implemented in software here, the device needs continuous servicing or
  the host will think it disconnected. The sketch calls
  `DigiJoystick.update()` every pass through `loop()` and uses
  `DigiJoystick.delay()` (which keeps servicing USB while it waits)
  wherever a short pause is needed for debounce.

## Verifying it before wiring a real pedal

With the Digispark plugged in (and its boot delay past), open any
webpage's browser JS console and run:

```js
window.addEventListener("gamepadconnected", e => console.log(e.gamepad));
```

Then short **P2 to GND** by hand with a jumper wire — you should see the
`gamepadconnected` event fire (Chrome/Edge require at least one button
press or axis movement before a gamepad is reported at all — that's a
browser behavior, not something this sketch controls). After that,
`navigator.getGamepads()[0].buttons[0].pressed` will read `true` while
the pin is held low and `false` when released. Confirm that before
soldering a permanent pedal to it.

## Wiring it to the console

Nothing to configure in TicketsCAD itself — `console-hid.js` polls every
connected gamepad's button 0 automatically. Select a channel strip with
real audio engaged (a "Matrix Audio" or "Join Intercom" checkbox that's
been checked — see `docs/COMMS-CONSOLE-GUIDE.md` sections 2 and 6), then
press the pedal. If no selected strip has live audio to send, the console
shows a brief on-screen notice rather than doing nothing silently, so you
know the press registered even when there's nothing to key yet.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Nothing happens for several seconds after plugging in | Normal — see the bootloader-delay quirk above |
| Browser never fires `gamepadconnected` | Some browsers only report a gamepad after the FIRST button press/axis move — try shorting P2 to GND before checking; also confirm the Digispark actually flashed (re-run Upload) |
| Button state never changes | Check wiring is P2-to-GND, not P2-to-VCC (the sketch uses `INPUT_PULLUP`, so the pin must go LOW, not HIGH, when pressed); confirm continuity across the switch with a multimeter |
| USB device intermittently disconnects/reconnects | Something in the sketch is blocking longer than expected — this shouldn't happen with the shipped sketch unmodified; if you've edited it, make sure you're still using `DigiJoystick.delay()` and not the plain Arduino `delay()` anywhere |
| Pedal fires twice on one press | Contact bounce exceeding the sketch's 25ms debounce window — increase `DEBOUNCE_MS` in the sketch and re-flash |

## Recording your build

`console-hid.js`'s own docblock asks that tested hardware be recorded by
exact make/model rather than left as an untested claim. If you build one
of these, note the specific switch/enclosure you used in
`specs/phase-152-comms-console-v2/tasks.md`'s Prerequisite 8 entry.
