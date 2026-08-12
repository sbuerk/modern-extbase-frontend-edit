/* Generated from Build/Sources/TypeScript — do not edit. */
import { parseProfileRecord } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/profileRecord.js";
const noResponseStatus = 0;
function interpretResponse(status, body) {
  if (status === 200) {
    const profile = parseProfileRecord(dataOf(body));
    return profile === null ? { kind: "error", status, codes: errorCodesFrom(body) } : { kind: "success", profile };
  }
  if (status === 422) {
    const errors = validationErrorsFrom(body);
    return Object.keys(errors.fieldErrors).length === 0 && errors.generalErrors.length === 0 ? { kind: "error", status, codes: errorCodesFrom(body) } : { kind: "validation", ...errors };
  }
  return { kind: "error", status, codes: errorCodesFrom(body) };
}
function validationErrorsFrom(body) {
  const fieldErrors = {};
  const generalErrors = [];
  for (const entry of errorEntriesOf(body)) {
    const message = entry.message;
    if (typeof message !== "string" || message === "") {
      continue;
    }
    const field = entry.field;
    if (typeof field !== "string" || field === "") {
      generalErrors.push(message);
      continue;
    }
    (fieldErrors[field] ??= []).push(message);
  }
  return { fieldErrors, generalErrors };
}
function errorCodesFrom(body) {
  const codes = [];
  for (const entry of errorEntriesOf(body)) {
    if (typeof entry.code === "number") {
      codes.push(entry.code);
    }
  }
  return codes;
}
function errorEntriesOf(body) {
  if (!isObject(body) || !Array.isArray(body.errors)) {
    return [];
  }
  return body.errors.filter(isObject);
}
function dataOf(body) {
  return isObject(body) ? body.data : null;
}
function isObject(value) {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
export {
  errorCodesFrom,
  interpretResponse,
  noResponseStatus,
  validationErrorsFrom
};
