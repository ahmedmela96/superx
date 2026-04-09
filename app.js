const storageKey = "superx_shipments_v1";

const seedData = [
  {
    tracking: "SX1001",
    merchant: "متجر السريع",
    customer: "أحمد علي",
    phone: "01000000001",
    city: "القاهرة",
    address: "مدينة نصر",
    cod: 350,
    courier: "محمد حسن",
    status: "in_transit",
    updatedAt: "2026-04-09 08:00"
  },
  {
    tracking: "SX1002",
    merchant: "متجر السريع",
    customer: "محمود سمير",
    phone: "01000000002",
    city: "الإسكندرية",
    address: "سيدي جابر",
    cod: 420,
    courier: "أحمد عادل",
    status: "new",
    updatedAt: "2026-04-09 08:20"
  },
  {
    tracking: "SX1003",
    merchant: "تاجر النخبة",
    customer: "أميرة طلعت",
    phone: "01000000003",
    city: "الجيزة",
    address: "الهرم",
    cod: 290,
    courier: "محمد حسن",
    status: "failed",
    updatedAt: "2026-04-08 19:10"
  }
];

const statusMap = {
  new: "جديد",
  in_transit: "قيد التوصيل",
  delivered: "تم التسليم",
  failed: "فشل التسليم",
  returned: "مرتجع"
};

function nowStamp() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")} ${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}

function loadShipments() {
  const cached = localStorage.getItem(storageKey);
  if (!cached) {
    localStorage.setItem(storageKey, JSON.stringify(seedData));
    return [...seedData];
  }
  return JSON.parse(cached);
}

function saveShipments(list) {
  localStorage.setItem(storageKey, JSON.stringify(list));
}

let shipments = loadShipments();

function setRole(role) {
  document.querySelectorAll(".panel").forEach((p) => p.classList.remove("active"));
  document.querySelectorAll("#rolesNav button").forEach((b) => b.classList.remove("active"));
  document.getElementById(role).classList.add("active");
  document.querySelector(`#rolesNav button[data-role='${role}']`).classList.add("active");
}

function statusBadge(status) {
  return `<span class='badge badge-${status}'>${statusMap[status]}</span>`;
}

function renderAdminStats() {
  const total = shipments.length;
  const delivered = shipments.filter((s) => s.status === "delivered").length;
  const returns = shipments.filter((s) => s.status === "returned").length;
  const failed = shipments.filter((s) => s.status === "failed").length;
  const merchants = new Set(shipments.map((s) => s.merchant)).size;

  const stats = [
    { label: "إجمالي الشحنات", value: total },
    { label: "تم التسليم", value: delivered },
    { label: "فشل التسليم", value: failed },
    { label: "المرتجعات", value: returns },
    { label: "عدد التجار", value: merchants }
  ];

  document.getElementById("adminStats").innerHTML = stats
    .map((s) => `<article class='stat'><div class='label'>${s.label}</div><div class='value'>${s.value}</div></article>`)
    .join("");
}

function renderAdminShipments() {
  const filter = document.getElementById("statusFilter").value;
  const rows = shipments
    .filter((s) => filter === "all" || s.status === filter)
    .map(
      (s) => `
      <tr>
        <td>${s.tracking}</td>
        <td>${s.merchant}</td>
        <td>${s.customer}</td>
        <td>${s.courier}</td>
        <td>${s.city}</td>
        <td>${statusBadge(s.status)}</td>
        <td>${s.cod} ج.م</td>
      </tr>`
    )
    .join("");

  document.getElementById("adminShipments").innerHTML = rows || `<tr><td colspan='7'>لا توجد بيانات</td></tr>`;
}

function renderMerchantShipments() {
  const merchantRows = shipments
    .filter((s) => s.merchant === "متجر السريع")
    .map(
      (s) => `
      <tr>
        <td>${s.tracking}</td>
        <td>${s.customer}</td>
        <td>${s.phone}</td>
        <td>${statusBadge(s.status)}</td>
        <td>${s.updatedAt}</td>
      </tr>
    `
    )
    .join("");

  document.getElementById("merchantShipments").innerHTML = merchantRows || `<tr><td colspan='5'>لا توجد شحنات</td></tr>`;
}

function renderCourierTasks() {
  const tasks = shipments
    .filter((s) => ["new", "in_transit", "failed"].includes(s.status))
    .map(
      (s) => `
        <article class='task'>
          <strong>${s.tracking} - ${s.customer}</strong>
          <span>${s.city} | ${s.address}</span>
          <span>الحالة الحالية: ${statusMap[s.status]}</span>
          <div class='task-actions'>
            <button data-tracking='${s.tracking}' data-next='delivered'>تم التسليم</button>
            <button data-tracking='${s.tracking}' data-next='failed'>فشل التسليم</button>
            <button data-tracking='${s.tracking}' data-next='returned'>مرتجع</button>
            <button data-tracking='${s.tracking}' data-next='in_transit'>تأجيل</button>
          </div>
        </article>
      `
    )
    .join("");

  document.getElementById("courierTasks").innerHTML = tasks || "<p>لا توجد مهام حالياً.</p>";
}

function refreshAll() {
  renderAdminStats();
  renderAdminShipments();
  renderMerchantShipments();
  renderCourierTasks();
}

document.getElementById("rolesNav").addEventListener("click", (e) => {
  if (e.target.tagName === "BUTTON") {
    setRole(e.target.dataset.role);
  }
});

document.getElementById("statusFilter").addEventListener("change", renderAdminShipments);

document.getElementById("shipmentForm").addEventListener("submit", (e) => {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target).entries());

  const tracking = `SX${Math.floor(1000 + Math.random() * 9000)}`;
  shipments.unshift({
    tracking,
    merchant: data.merchant,
    customer: data.customer,
    phone: data.phone,
    city: data.city,
    address: data.address,
    cod: Number(data.cod),
    courier: "قيد التعيين",
    status: "new",
    updatedAt: nowStamp()
  });

  saveShipments(shipments);
  refreshAll();
  e.target.reset();
  alert(`تم إنشاء الشحنة بنجاح. رقم التتبع: ${tracking}`);
});

document.getElementById("trackingForm").addEventListener("submit", (e) => {
  e.preventDefault();
  const tracking = document.getElementById("trackingInput").value.trim().toUpperCase();
  const item = shipments.find((s) => s.tracking === tracking);
  const result = document.getElementById("trackingResult");

  if (!item) {
    result.innerHTML = `❌ لا يوجد شحنة برقم <strong>${tracking}</strong>.`;
    return;
  }

  result.innerHTML = `
    <strong>${item.tracking}</strong><br />
    العميل: ${item.customer}<br />
    المدينة: ${item.city}<br />
    الحالة: ${statusBadge(item.status)}<br />
    آخر تحديث: ${item.updatedAt}
  `;
});

document.getElementById("courierTasks").addEventListener("click", (e) => {
  if (e.target.tagName !== "BUTTON") return;
  const tracking = e.target.dataset.tracking;
  const next = e.target.dataset.next;
  shipments = shipments.map((s) => (s.tracking === tracking ? { ...s, status: next, updatedAt: nowStamp() } : s));
  saveShipments(shipments);
  refreshAll();
});

refreshAll();
