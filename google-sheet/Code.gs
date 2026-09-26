/**
 * Dentspace: receives each online booking and files it in this Google Sheet.
 *
 * Tabs it keeps up to date:
 *   Bookings - one row per booking (newest at the bottom)
 *   Patients - one row per patient (name + mobile), with visit count and last booking
 *
 * SETUP (once, signed in as dentspacedmd@gmail.com):
 *  1. Paste this whole file into Extensions > Apps Script (replace what is there).
 *  2. Change SECRET below to any long random text. Keep a copy; the server needs the same text.
 *  3. Run setup() once (press Run, allow access). It creates the two tabs.
 *  4. Deploy > New deployment > type "Web app": Execute as "Me", Who has access "Anyone". Deploy.
 *  5. Copy the Web app URL (starts with https://script.google.com/macros/s/...).
 */
const SECRET = 'CHANGE-ME-TO-A-LONG-RANDOM-TEXT';

const BOOKING_HEADERS = ['Reference', 'Booked at', 'Name', 'Mobile', 'Email', 'Service', 'Date', 'Time', 'Status', 'Source'];
const PATIENT_HEADERS = ['Name', 'Mobile', 'Email', 'Bookings', 'Last booking', 'First seen'];

function setup() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  [['Bookings', BOOKING_HEADERS], ['Patients', PATIENT_HEADERS]].forEach(([name, headers]) => {
    const sh = ss.getSheetByName(name) || ss.insertSheet(name);
    sh.getRange(1, 1, 1, headers.length).setValues([headers]).setFontWeight('bold').setBackground('#E2F0ED');
    sh.setFrozenRows(1);
  });
  const blank = ss.getSheetByName('Sheet1');
  if (blank && ss.getSheets().length > 1 && blank.getLastRow() === 0) ss.deleteSheet(blank);
  ss.getSheetByName('Bookings').getRange('D:D').setNumberFormat('@'); // keep the leading 0 in phone numbers
  ss.getSheetByName('Patients').getRange('B:B').setNumberFormat('@');
}

function doPost(e) {
  let d;
  try { d = JSON.parse(e.postData.contents); } catch (err) { return out('bad request'); }
  if (!d || d.secret !== SECRET) return out('forbidden');

  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const ss = SpreadsheetApp.getActiveSpreadsheet();
    const clean = v => { v = String(v == null ? '' : v); return /^[=+\-@]/.test(v) ? "'" + v : v; }; // stop spreadsheet formulas
    const bk = ss.getSheetByName('Bookings');
    // Phone numbers, dates and times are written as plain text so Sheets keeps "0917..." and "9:00 AM" as typed.
    const putText = (sh, row, col, val) => sh.getRange(row, col).setNumberFormat('@').setValue(String(val));
    const digits = v => String(v).replace(/\D/g, '').replace(/^0+/, ''); // 09170000009 and 9170000009 are the same person
    bk.appendRow([d.ref, d.bookedAt, d.name, '', d.email, d.service, '', '', d.status, d.source].map(clean));
    const br = bk.getLastRow();
    putText(bk, br, 4, d.mobile); putText(bk, br, 7, d.date); putText(bk, br, 8, d.time);

    const pt = ss.getSheetByName('Patients');
    const rows = pt.getLastRow() > 1 ? pt.getRange(2, 1, pt.getLastRow() - 1, PATIENT_HEADERS.length).getValues() : [];
    const i = rows.findIndex(r => digits(r[1]) === digits(d.mobile) && String(r[0]).toLowerCase() === String(d.name).toLowerCase());
    const when = d.date + ' ' + d.time;
    if (i >= 0) {
      const r = i + 2;
      pt.getRange(r, 4).setValue((Number(rows[i][3]) || 0) + 1);
      putText(pt, r, 5, when);
      putText(pt, r, 2, d.mobile);
      if (d.email && !rows[i][2]) pt.getRange(r, 3).setValue(clean(d.email));
    } else {
      pt.appendRow([d.name, '', d.email, 1, '', d.bookedAt].map(clean));
      const pr = pt.getLastRow();
      putText(pt, pr, 2, d.mobile); putText(pt, pr, 5, when);
    }
    return out('ok');
  } finally {
    lock.releaseLock();
  }
}

function out(s) { return ContentService.createTextOutput(s); }
