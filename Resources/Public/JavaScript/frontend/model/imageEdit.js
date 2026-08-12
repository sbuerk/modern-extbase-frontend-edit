/* Generated from Build/Sources/TypeScript — do not edit. */
const imageField = "image";
const imageAccept = "image/jpeg,image/png,image/gif,image/webp";
function isDisplayable(image) {
  return image !== null && image.publicUrl !== "";
}
function imageAlternative(image, template, name) {
  if (image.alternative !== "") {
    return image.alternative;
  }
  return template.replace("%s", name);
}
function uploadFailureMessages(messages, notice) {
  if (notice === "" || messages.includes(notice)) {
    return [...messages];
  }
  return [...messages, notice];
}
export {
  imageAccept,
  imageAlternative,
  imageField,
  isDisplayable,
  uploadFailureMessages
};
