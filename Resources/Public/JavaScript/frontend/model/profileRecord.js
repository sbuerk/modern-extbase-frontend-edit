/* Generated from Build/Sources/TypeScript — do not edit. */
import { fieldsOf } from "@sbuerk/modern-extbase-frontend-edit/frontend/model/fieldDefinitions.js";
function parseProfileRecord(value) {
  if (!isObject(value)) {
    return null;
  }
  const uid = readUid(value.uid);
  if (uid === null) {
    return null;
  }
  return {
    uid,
    shortname: readString(value.shortname),
    firstname: readString(value.firstname),
    lastname: readString(value.lastname),
    birthday: readString(value.birthday),
    bio: readString(value.bio),
    hidden: value.hidden === true,
    image: parseProfileImage(value.image),
    addresses: readChildren(value.addresses, parseAddressRecord),
    emails: readChildren(value.emails, parseEmailRecord)
  };
}
function parseProfileImage(value) {
  if (!isObject(value)) {
    return null;
  }
  const uid = readUid(value.uid);
  if (uid === null) {
    return null;
  }
  return {
    uid,
    fileUid: readUid(value.fileUid) ?? 0,
    publicUrl: readString(value.publicUrl),
    name: readString(value.name),
    extension: readString(value.extension),
    mimeType: readString(value.mimeType),
    size: readNumber(value.size) ?? 0,
    title: readString(value.title),
    alternative: readString(value.alternative),
    width: readNumber(value.width),
    height: readNumber(value.height)
  };
}
function displayName(profile) {
  const name = `${profile.firstname} ${profile.lastname}`.trim();
  return name === "" ? profile.shortname : name;
}
function parseAddressRecord(value) {
  if (!isObject(value)) {
    return null;
  }
  const uid = readUid(value.uid);
  if (uid === null) {
    return null;
  }
  return {
    uid,
    type: readString(value.type),
    line1: readString(value.line1),
    line2: readString(value.line2),
    hidden: value.hidden === true
  };
}
function parseEmailRecord(value) {
  if (!isObject(value)) {
    return null;
  }
  const uid = readUid(value.uid);
  if (uid === null) {
    return null;
  }
  return {
    uid,
    type: readString(value.type),
    email: readString(value.email),
    hidden: value.hidden === true
  };
}
function recordOf(profile, target) {
  if (target.child === null) {
    return profile;
  }
  if (target.childUid === null) {
    return null;
  }
  return childRecord(profile, target.child, target.childUid);
}
function childRecord(profile, child, childUid) {
  return childrenOf(profile, child).find((record) => record.uid === childUid) ?? null;
}
function childrenOf(profile, child) {
  return child === "address" ? profile.addresses : profile.emails;
}
function childUids(profile, child) {
  return childrenOf(profile, child).map((record) => record.uid);
}
function fieldValue(profile, target, field) {
  const record = recordOf(profile, target);
  if (record === null) {
    return "";
  }
  const value = record[field];
  return typeof value === "string" ? value : "";
}
function recordValues(profile, target) {
  const values = {};
  for (const definition of fieldsOf(target)) {
    values[definition.name] = fieldValue(profile, target, definition.name);
  }
  return values;
}
function isChildHidden(profile, child, childUid) {
  var _a;
  return ((_a = childRecord(profile, child, childUid)) == null ? void 0 : _a.hidden) ?? false;
}
function movedChildOrder(profile, child, childUid, offset) {
  const order = childUids(profile, child);
  const from = order.indexOf(childUid);
  if (from === -1) {
    return order;
  }
  const to = from + offset;
  if (to < 0 || to >= order.length) {
    return order;
  }
  const moved = [...order];
  moved.splice(from, 1);
  moved.splice(to, 0, childUid);
  return moved;
}
function readChildren(value, parse) {
  if (!Array.isArray(value)) {
    return [];
  }
  const records = [];
  for (const entry of value) {
    const record = parse(entry);
    if (record !== null) {
      records.push(record);
    }
  }
  return records;
}
function readUid(value) {
  return typeof value === "number" && Number.isInteger(value) && value > 0 ? value : null;
}
function readNumber(value) {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}
function readString(value) {
  return typeof value === "string" ? value : "";
}
function isObject(value) {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
export {
  childRecord,
  childUids,
  childrenOf,
  displayName,
  fieldValue,
  isChildHidden,
  movedChildOrder,
  parseAddressRecord,
  parseEmailRecord,
  parseProfileImage,
  parseProfileRecord,
  recordOf,
  recordValues
};
