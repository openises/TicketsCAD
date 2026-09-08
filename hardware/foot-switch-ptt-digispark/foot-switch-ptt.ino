/*
 * TicketsCAD Communications Console — physical PTT foot switch
 * ==============================================================
 * Digispark (ATtiny85) as a single-button USB HID gamepad.
 *
 * Console-side consumer: assets/js/console-hid.js (Phase 152 prerequisite
 * #8) polls navigator.getGamepads() every 50ms and treats
 * buttons[0].pressed as "key the currently-selected channel". This sketch
 * makes a $2-3 microcontroller present as exactly that: a standards-
 * compliant USB HID gamepad with one button, no drivers, no companion
 * software, recognized identically by Chrome/Firefox/Edge on Windows,
 * macOS, and Linux.
 *
 * Why a real gamepad and not a keyboard-emulating pedal: console-hid.js's
 * keyboard fallback path deliberately ignores keystrokes while focus is
 * inside an INPUT/TEXTAREA/SELECT/contenteditable element (so typing an
 * actual backtick character in, say, an incident note never mis-fires
 * PTT). A real Gamepad API button has no such focus filter -- it fires
 * from anywhere on the page, which is what an actual dispatcher wants
 * from a foot pedal.
 *
 * ── Hardware ──────────────────────────────────────────────────────────
 * Board:   Digistump Digispark (any revision -- ATtiny85, 16.5MHz)
 * Library: DigiJoystick (ships with the Digistump Arduino board package;
 *          no separate install needed once the board package is added)
 * Switch:  any normally-open momentary foot switch / pedal, wired
 *          between pin P2 and GND.
 *
 * DO NOT use P3 or P4 for the switch (or anything else) -- those are the
 * USB D-/D+ lines, physically wired to the Digispark's USB connector for
 * the bit-banged V-USB stack this library depends on; driving them will
 * break USB communication. P2 is a clean, unshared, low-risk choice:
 *   - P1 doubles as the onboard LED on most Digispark boards (usable, but
 *     the LED will flicker with pedal state -- fine as a "pressed"
 *     indicator if you want that, but not this sketch's default).
 *   - P5 is the physical RESET pin; reassigning it as GPIO requires a
 *     fuse change that can only be undone with a high-voltage programmer
 *     if something goes wrong -- not worth the risk for one button.
 *
 * ── Two real quirks of this platform, not bugs in this sketch ─────────
 * 1. Every time you plug the Digispark in, its bootloader (Micronucleus)
 *    waits several seconds listening for a new firmware upload before it
 *    hands off to this program -- so the gamepad will NOT appear to the
 *    OS/browser instantly on plug-in. Fine for a pedal that stays plugged
 *    in at a workstation; don't mistake the delay for a fault.
 * 2. This library implements USB entirely in software (V-USB) because
 *    the ATtiny85 has no real USB hardware, so it must be serviced
 *    constantly or the host will think the device died. That is why this
 *    sketch NEVER calls the plain Arduino delay() -- it uses
 *    DigiJoystick.delay(), which keeps polling USB internally while it
 *    waits, and calls DigiJoystick.update() every pass through loop().
 *
 * ── Verifying it works, before you've wired a real pedal ───────────────
 * Open any page's JS console and run:
 *     window.addEventListener("gamepadconnected", e => console.log(e.gamepad));
 * then short P2 to GND with a jumper wire by hand and watch:
 *     navigator.getGamepads()[0].buttons[0].pressed
 * flip true/false. See the accompanying README.md for the full build,
 * flashing, and troubleshooting guide.
 */
#include "DigiJoystick.h"

#define PEDAL_PIN 2
#define DEBOUNCE_MS 25

void setup() {
    pinMode(PEDAL_PIN, INPUT_PULLUP);   // pedal shorts this pin to GND when pressed
}

void loop() {
    static bool lastStable = false;     // false = not pressed
    static bool lastRaw    = false;

    bool raw = (digitalRead(PEDAL_PIN) == LOW);

    if (raw != lastRaw) {
        // State changed -- wait out mechanical contact bounce, then
        // re-read to confirm the new state is real, not a bounce spike.
        DigiJoystick.delay(DEBOUNCE_MS);
        raw = (digitalRead(PEDAL_PIN) == LOW);
    }
    lastRaw = raw;

    if (raw != lastStable) {
        lastStable = raw;
        // Bit 0 of the low buttons byte = "Button 1" in the HID report =
        // navigator.getGamepads()[i].buttons[0] in every browser --
        // exactly what console-hid.js's GAMEPAD_BUTTON_INDEX (0) polls.
        // High byte (buttons 9-16) stays 0 -- unused by this sketch.
        DigiJoystick.setButtons(lastStable ? 0x01 : 0x00, 0x00);
    }

    DigiJoystick.update();
}
