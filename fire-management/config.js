// config.js - Cấu hình hệ thống
const mapConfig = {
  center: [10.386, 105.435], // Tọa độ trung tâm An Giang
  zoom: 10,
  tileLayer: "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
  attribution:
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
};

const dangerLevels = {
  low: {
    text: "Thấp",
    color: "green",
  },
  medium: {
    text: "Trung bình",
    color: "orange",
  },
  high: {
    text: "Cao",
    color: "red",
  },
};

const districts = [
  "Long Xuyên",
  "Châu Đốc",
  "An Phú",
  "Tân Châu",
  "Phú Tân",
  "Châu Phú",
  "Tịnh Biên",
  "Tri Tôn",
  "Chợ Mới",
  "Thoại Sơn",
];
