/* Generated from Build/Sources/TypeScript — do not edit. */
const profileFields = [
  { name: "shortname", control: "line", maxLength: 255 },
  { name: "firstname", control: "line", maxLength: 255 },
  { name: "lastname", control: "line", maxLength: 255 },
  { name: "birthday", control: "date" },
  { name: "bio", control: "text", maxLength: 5e3 }
];
const addressFields = [
  { name: "type", control: "choice", choices: ["home", "work", "others"], initial: "others" },
  { name: "line1", control: "line", maxLength: 255 },
  { name: "line2", control: "line", maxLength: 255 }
];
const emailFields = [
  { name: "type", control: "choice", choices: ["private", "business", "others"], initial: "others" },
  { name: "email", control: "line", maxLength: 255 }
];
function fieldsOfChild(child) {
  switch (child) {
    case "address":
      return addressFields;
    case "email":
      return emailFields;
    default:
      return profileFields;
  }
}
function fieldsOf(target) {
  return fieldsOfChild(target.child);
}
function initialValues(fields) {
  const values = {};
  for (const definition of fields) {
    values[definition.name] = definition.initial ?? "";
  }
  return values;
}
export {
  addressFields,
  emailFields,
  fieldsOf,
  fieldsOfChild,
  initialValues,
  profileFields
};
