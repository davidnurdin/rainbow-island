/* Minimal Amstrad CPC 6128 for running and studying one disc game.
 *
 *   cpc <disk.dsk> <outdir> <frames> [options]
 *     -k "frame:row.bit+,frame:row.bit-,..."   key presses (keyboard matrix row 0-9, bit 0-7)
 *     -s "f1,f2,..."    save a screenshot (PPM) at those frames
 *     -S n              save a screenshot every n frames
 *     -d "f1,f2,..."    dump memory (128 KB + state) at those frames
 *     -t lo-hi          log PCs executed in that range (once per address) to trace.txt
 *
 * No ROMs: the boot sector is started directly (what |CPM does), and the loader's sector-read
 * routine at #01A7 is replaced by a high-level version (A = file number, HL = destination).
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "z80.h"

static uint8_t ram[8][16384];
static int bank_map[4] = {0, 1, 2, 3};
static Z80 cpu;

/* disc */
static uint8_t disk_sectors[40][9][512];

/* gate array */
static uint8_t ga_pen, ga_palette[17], ga_mode, ga_counter;
static int irq_pending;
/* crtc */
static uint8_t crtc_sel, crtc[18] = {63, 40, 46, 0x8e, 38, 0, 25, 30, 0, 7, 0, 0, 0x30, 0, 0, 0, 0, 0};
/* ppi / psg */
static uint8_t ppi_a, ppi_c, psg_sel, psg[16], psg_written[16];
static uint8_t keys[10];
static int vsync;

/* per-scanline captured state for rendering */
static uint8_t line_pal[312][17], line_mode[312];
static uint16_t line_start[312];
static int scan;

static uint8_t coverage[65536];
static uint16_t ring[256];
static unsigned ring_pos;
static int reg_pc[16], nreg, reg_hits;
static int refill_pc[4], refill_addr[4], refill_val[4], refill_n[4], nrefill;
static int fpoke_frame[32], fpoke_addr[32], fpoke_val[32], nfpoke;
static int poke_pc[16], poke_addr[16], poke_val[16], poke_done[16], npoke;
static FILE *reg_log;
static int reg_ix_bytes;
static int crashed, hle, break_pc = -1, break_hits;

static int ur_lo = -1, ur_hi = -1;
static unsigned ur_count[65536];
static uint8_t mem_read(uint16_t a) {
    if (a >= ur_lo && a <= ur_hi && cpu.pc != a) ur_count[cpu.pc]++;
    return ram[bank_map[a >> 14]][a & 0x3fff];
}
static int watch_lo[4] = {-1, -1, -1, -1}, watch_hi[4] = {-1, -1, -1, -1}, nwatch, cur_frame;
static FILE *log_file;
static int fdc_first_byte, uw_lo = -1, uw_hi = -1;
static unsigned uw_count[65536];
static void mem_write(uint16_t a, uint8_t v) {
    if (fdc_first_byte) { fdc_first_byte = 0; fprintf(log_file, "  sector data -> %04x\n", a); }
    for (int q = 0; q < nwatch; q++)
        if (a >= watch_lo[q] && a <= watch_hi[q]) fprintf(log_file, "write %04x=%02x pc=%04x f=%d\n", a, v, cpu.pc, cur_frame);
    if (a >= uw_lo && a <= uw_hi) uw_count[cpu.pc]++;
    ram[bank_map[a >> 14]][a & 0x3fff] = v;
}

static void set_banks(uint8_t cfg) {
    static const int maps[8][4] = {
        {0, 1, 2, 3}, {0, 1, 2, 7}, {4, 5, 6, 7}, {0, 3, 2, 7},
        {0, 4, 2, 3}, {0, 5, 2, 3}, {0, 6, 2, 3}, {0, 7, 2, 3}};
    memcpy(bank_map, maps[cfg & 7], sizeof bank_map);
}

static uint8_t seen_rows[16][65536 / 64];
static uint8_t keyboard_row(void) { int row = ppi_c & 15;
    if (!seen_rows[row][cpu.pc / 64]) { seen_rows[row][cpu.pc / 64] = 1; fprintf(log_file, "key row %d pc=%04x\n", row, cpu.pc); } return row < 10 ? (uint8_t)~keys[row] : 0xff; }

static void psg_bus(void) {
    switch (ppi_c >> 6) {
    case 3: psg_sel = ppi_a & 15; break;
    case 2: psg[psg_sel] = ppi_a; psg_written[psg_sel] = 1; break;
    }
}

static uint8_t fdc_status(void);
static uint8_t fdc_read_data(void);
static void fdc_write_data(uint8_t v);

static uint8_t port_in(uint16_t port) {
    if (!(port & 0x0800)) {
        switch ((port >> 8) & 3) {
        case 0: /* PPI A: PSG read */
            if ((ppi_c >> 6) == 1) return psg_sel == 14 ? keyboard_row() : psg[psg_sel];
            return ppi_a;
        case 1: return 0x5e | (vsync ? 1 : 0); /* Amstrad, 50 Hz */
        case 2: return ppi_c;
        }
    }
    if ((port & 0x0581) == 0x0100) return fdc_status();
    if ((port & 0x0581) == 0x0101) return fdc_read_data();
    if (!(port & 0x4000) && ((port >> 8) & 3) == 3) return crtc_sel >= 12 && crtc_sel < 18 ? crtc[crtc_sel] : 0;
    return 0xff;
}

static void port_out(uint16_t port, uint8_t v) {
    if ((port & 0xc000) == 0x4000) { /* gate array / RAM banking */
        switch (v >> 6) {
        case 0: ga_pen = v & 0x10 ? 16 : v & 15; break;
        case 1: ga_palette[ga_pen] = v & 0x1f; break;
        case 2: ga_mode = v & 3; if (v & 0x10) { ga_counter = 0; irq_pending = 0; } break;
        case 3: set_banks(v); break;
        }
    }
    if (!(port & 0x4000)) {
        int f = (port >> 8) & 3;
        if (f == 0) crtc_sel = v & 31;
        else if (f == 1 && crtc_sel < 18) crtc[crtc_sel] = v;
    }
    if ((port & 0x0581) == 0x0101) fdc_write_data(v);
    if (!(port & 0x0800)) {
        switch ((port >> 8) & 3) {
        case 0: ppi_a = v; psg_bus(); break;
        case 2: ppi_c = v; psg_bus(); break;
        case 3:
            if (!(v & 0x80)) { int bit = (v >> 1) & 7; if (v & 1) ppi_c |= 1 << bit; else ppi_c &= ~(1 << bit); psg_bus(); }
            break;
        }
    }
}

/* ---- disc ---- */
static int load_dsk(const char *path) {
    FILE *f = fopen(path, "rb");
    if (!f) return 0;
    static uint8_t buf[1 << 20];
    size_t n = fread(buf, 1, sizeof buf, f);
    fclose(f);
    int tracks = buf[0x30], tsize = buf[0x32] | buf[0x33] << 8;
    for (int t = 0; t < tracks && t < 40; t++) {
        uint8_t *th = buf + 0x100 + (size_t)t * tsize;
        if ((size_t)(th - buf) >= n) break;
        int ns = th[0x15];
        uint8_t *data = th + 0x100;
        for (int s = 0; s < ns; s++) {
            int r = th[0x18 + s * 8 + 2];
            if (r >= 0x41 && r <= 0x49) memcpy(disk_sectors[t][r - 0x41], data, 512);
            data += 512;
        }
    }
    return 1;
}

#include "fdc.inc"

/* the loader's "read file" routine: A = file number in the table at #01EF, HL = destination */
static void hle_read_file(void) {
    int n = cpu.a;
    uint16_t entry = 0x1ef + n * 3;
    int sector = mem_read(entry), track = mem_read(entry + 1), count = mem_read(entry + 2);
    uint16_t dest = cpu.h << 8 | cpu.l;
    fprintf(log_file, "frame? read file %d -> %04x (track %d sector %d count %d) ret=%04x\n", n, dest, track, sector, count,
            mem_read(cpu.sp) | mem_read(cpu.sp + 1) << 8);
    set_banks(0xc0);
    for (int i = 0; i < count; i++) {
        for (int b = 0; b < 512; b++) mem_write(dest + b, disk_sectors[track][sector][b]);
        dest += 512;
        if (++sector == 9) { sector = 0; track++; }
    }
    cpu.f |= 1; /* carry: success */
    cpu.pc = mem_read(cpu.sp) | mem_read(cpu.sp + 1) << 8;
    cpu.sp += 2;
}

/* ---- video ---- */
static const uint8_t hw_rgb[32][3] = {
    {128,128,128},{128,128,128},{0,255,128},{255,255,128},{0,0,128},{255,0,128},{0,128,128},{255,128,128},
    {255,0,128},{255,255,128},{255,255,0},{255,255,255},{255,0,0},{255,0,255},{255,128,0},{255,128,255},
    {0,0,128},{0,255,128},{0,255,0},{0,255,255},{0,0,0},{0,0,255},{0,128,0},{0,128,255},
    {128,0,128},{128,255,128},{128,255,0},{128,255,255},{128,0,0},{128,0,255},{128,128,0},{128,128,255}};

static void pixel_pens(uint8_t b, int mode, int *pens) {
    if (mode == 0) {
        pens[0] = ((b >> 7) & 1) | ((b >> 2) & 2) | ((b >> 3) & 4) | ((b << 2) & 8);
        pens[1] = ((b >> 6) & 1) | ((b >> 1) & 2) | ((b >> 2) & 4) | ((b << 3) & 8);
    } else if (mode == 1) {
        for (int i = 0; i < 4; i++) pens[i] = ((b >> (7 - i)) & 1) | (((b >> (3 - i)) & 1) << 1);
    } else {
        for (int i = 0; i < 8; i++) pens[i] = (b >> (7 - i)) & 1;
    }
}

/* the displayed picture, one row per visible scanline; first displayed line = line after vsync */
static void save_screen(const char *dir, int frame) {
    int r1 = crtc[1], rows = crtc[6], r9 = crtc[9] + 1;
    int height = rows * r9, width = r1 * 8; /* mode 1 resolution */
    if (width <= 0 || height <= 0 || width > 1024 || height > 312) return;
    int first = ((crtc[4] + 1) - crtc[7]) * r9 + crtc[5];
    char name[512];
    snprintf(name, sizeof name, "%s/screen-%05d.ppm", dir, frame);
    FILE *f = fopen(name, "wb");
    fprintf(f, "P6 %d %d 255\n", width, height);
    for (int y = 0; y < height; y++) {
        int ln = (first + y) % 312;
        int mode = line_mode[ln];
        uint16_t start = line_start[ln];
        int row = y / r9, ra = y % r9;
        for (int c = 0; c < r1; c++) {
            uint16_t ma = start + row * r1 + c;
            uint16_t addr = ((ma & 0x3000) << 2) | ((ra & 7) << 11) | ((ma & 0x3ff) << 1);
            for (int k = 0; k < 2; k++) {
                uint8_t b = ram[(uint16_t)(addr + k) >> 14][(addr + k) & 0x3fff];
                int pens[8];
                pixel_pens(b, mode, pens);
                for (int x = 0; x < 4; x++) {
                    int pen = mode == 0 ? pens[x / 2] : mode == 1 ? pens[x] : pens[x * 2];
                    fwrite(hw_rgb[line_pal[ln][pen]], 1, 3, f);
                }
            }
        }
    }
    fclose(f);
}

static void dump_named(const char *dir, const char *base);
static void dump_state(const char *dir, int frame) {
    char n[64]; snprintf(n, sizeof n, "dump-%05d", frame); dump_named(dir, n);
}
static void dump_named(const char *dir, const char *base) {
    char name[512];
    snprintf(name, sizeof name, "%s/%s.bin", dir, base);
    FILE *f = fopen(name, "wb");
    for (int b = 0; b < 8; b++) fwrite(ram[b], 1, 16384, f);
    uint8_t st[64] = {0};
    st[0] = cpu.a; st[1] = cpu.f; st[2] = cpu.b; st[3] = cpu.c; st[4] = cpu.d; st[5] = cpu.e; st[6] = cpu.h; st[7] = cpu.l;
    st[8] = cpu.ix; st[9] = cpu.ix >> 8; st[10] = cpu.iy; st[11] = cpu.iy >> 8; st[12] = cpu.sp; st[13] = cpu.sp >> 8;
    st[14] = cpu.pc; st[15] = cpu.pc >> 8; st[16] = ga_mode;
    memcpy(st + 17, ga_palette, 17);
    memcpy(st + 34, crtc, 18);
    st[52] = bank_map[3];
    fwrite(st, 1, 64, f);
    fwrite(line_pal, 1, sizeof line_pal, f);
    fwrite(line_mode, 1, sizeof line_mode, f);
    fclose(f);
}

static int in_list(const char *list, int frame) {
    if (!list) return 0;
    const char *p = list;
    while (*p) {
        if (atoi(p) == frame) return 1;
        p = strchr(p, ',');
        if (!p) break;
        p++;
    }
    return 0;
}

int main(int argc, char **argv) {
    if (argc < 4) { fprintf(stderr, "usage: cpc disk.dsk outdir frames [options]\n"); return 1; }
    const char *dir = argv[2];
    int frames = atoi(argv[3]);
    const char *key_script = NULL, *shots = NULL, *dumps = NULL;
    int shot_every = 0, trace_lo = -1, trace_hi = -1;
    for (int i = 4; i + 1 < argc; i += 2) {
        if (!strcmp(argv[i], "-k")) key_script = argv[i + 1];
        else if (!strcmp(argv[i], "-s")) shots = argv[i + 1];
        else if (!strcmp(argv[i], "-S")) shot_every = atoi(argv[i + 1]);
        else if (!strcmp(argv[i], "-d")) dumps = argv[i + 1];
        else if (!strcmp(argv[i], "-w") && nwatch < 4) { sscanf(argv[i + 1], "%x-%x", &watch_lo[nwatch], &watch_hi[nwatch]); nwatch++; }
        else if (!strcmp(argv[i], "-b")) sscanf(argv[i + 1], "%x", &break_pc);
        else if (!strcmp(argv[i], "-W")) sscanf(argv[i + 1], "%x-%x", &uw_lo, &uw_hi);
        else if (!strcmp(argv[i], "-r")) { const char *p = argv[i + 1]; while (p && nreg < 16) { sscanf(p, "%x", &reg_pc[nreg++]); p = strchr(p, ','); if (p) p++; } }
        else if (!strcmp(argv[i], "-p") && npoke < 16) { sscanf(argv[i + 1], "%x:%x=%x", &poke_pc[npoke], &poke_addr[npoke], &poke_val[npoke]); npoke++; }
        else if (!strcmp(argv[i], "-c") && nrefill < 4) { sscanf(argv[i + 1], "%x:%x=%x:%d", &refill_pc[nrefill], &refill_addr[nrefill], &refill_val[nrefill], &refill_n[nrefill]); nrefill++; }
        else if (!strcmp(argv[i], "-R")) sscanf(argv[i + 1], "%x-%x", &ur_lo, &ur_hi);
        else if (!strcmp(argv[i], "-X")) reg_ix_bytes = atoi(argv[i + 1]);
        else if (!strcmp(argv[i], "-F") && nfpoke < 32) { sscanf(argv[i + 1], "%d:%x=%x", &fpoke_frame[nfpoke], &fpoke_addr[nfpoke], &fpoke_val[nfpoke]); nfpoke++; }
        else if (!strcmp(argv[i], "-t")) sscanf(argv[i + 1], "%x-%x", &trace_lo, &trace_hi);
    }
    if (!load_dsk(argv[1])) { fprintf(stderr, "cannot read %s\n", argv[1]); return 1; }
    char name[512];
    snprintf(name, sizeof name, "%s/log.txt", dir);
    log_file = fopen(name, "w");
    snprintf(name, sizeof name, "%s/psg.bin", dir);
    FILE *psg_log = fopen(name, "wb");
    FILE *trace = NULL;
    snprintf(name, sizeof name, "%s/regs.txt", dir); reg_log = fopen(name, "w");
    if (trace_lo >= 0) { snprintf(name, sizeof name, "%s/trace.txt", dir); trace = fopen(name, "w"); }

    cpu.read = mem_read; cpu.write = mem_write; cpu.in = port_in; cpu.out = port_out;
    z80_reset(&cpu);
    memcpy(&ram[0][0x100], disk_sectors[0][0], 512);
    cpu.pc = 0x110; cpu.sp = 0xc000;

    long line_t = 0;
    for (int frame = 0; frame < frames; frame++) {
        /* keyboard script */
        if (key_script) {
            const char *p = key_script;
            while (*p) {
                int f, row, bit; char sign;
                if (sscanf(p, "%d:%d.%d%c", &f, &row, &bit, &sign) == 4 && f == frame) {
                    if (sign == '+') keys[row] |= 1 << bit; else keys[row] &= ~(1 << bit);
                }
                p = strchr(p, ',');
                if (!p) break;
                p++;
            }
        }
        cur_frame = frame;
        for (int q = 0; q < nfpoke; q++) if (fpoke_frame[q] == frame) mem_write(fpoke_addr[q], fpoke_val[q]);
        memset(psg_written, 0, sizeof psg_written);
        for (scan = 0; scan < 312; scan++) {
            vsync = scan < 8;
            line_t += 256;
            while (cpu.cycles < line_t) {
                if (hle && cpu.pc == 0x1a7 && mem_read(0x1a7) == 0x06 && mem_read(0x1a8) == 0x00) hle_read_file();
                if (trace && cpu.pc >= trace_lo && cpu.pc <= trace_hi && !coverage[cpu.pc])
                    fprintf(trace, "%d %04x\n", frame, cpu.pc);
                coverage[cpu.pc] = 1;
                for (int q = 0; q < nreg; q++) if (cpu.pc == reg_pc[q] && reg_hits < 2000000) {
                    reg_hits++;
                    fprintf(reg_log, "%d %d %04x a=%02x f=%02x bc=%02x%02x de=%02x%02x hl=%02x%02x ix=%04x iy=%04x sp=%04x\n", frame, scan, cpu.pc, cpu.a, cpu.f, cpu.b, cpu.c, cpu.d, cpu.e, cpu.h, cpu.l, cpu.ix, cpu.iy, cpu.sp);
                    if (reg_ix_bytes) { fprintf(reg_log, "   ix:"); for (int k = 0; k < 32; k++) fprintf(reg_log, " %02x", mem_read(cpu.ix + k)); fprintf(reg_log, "\n"); }
                }
                for (int q = 0; q < npoke; q++) if (cpu.pc == poke_pc[q] && !poke_done[q]) {
                    poke_done[q] = 1; mem_write(poke_addr[q], poke_val[q]);
                }
                for (int q = 0; q < nrefill; q++) if (cpu.pc == refill_pc[q] && refill_n[q] > 0 && mem_read(refill_addr[q]) == 0) {
                    refill_n[q]--; mem_write(refill_addr[q], refill_val[q]);
                }
                if (cpu.pc == break_pc && break_hits < 50) {
                    char bn[64]; snprintf(bn, sizeof bn, "bp%04x-%d", break_pc, break_hits++);
                    dump_named(dir, bn);
                }
                ring[ring_pos++ & 255] = cpu.pc;
                if (!crashed && cpu.pc < 0xa00 && !(cpu.pc >= 0x110 && cpu.pc < 0x300) && !(cpu.pc >= 0x38 && cpu.pc < 0x40)) {
                    crashed = 1;
                    fprintf(log_file, "CRASH frame %d scan %d pc=%04x sp=%04x\n", frame, scan, cpu.pc, cpu.sp);
                    for (int i = 0; i < 256; i++) fprintf(log_file, "%04x ", ring[(ring_pos + i) & 255]);
                    fprintf(log_file, "\n");
                }
                long before = cpu.cycles;
                z80_step(&cpu);
                long t = cpu.cycles - before;
                cpu.cycles = before + ((t + 3) & ~3); /* CPC wait states: 1 us steps */
                if (irq_pending && cpu.iff1 && !cpu.ei_delay) {
                    long b2 = cpu.cycles;
                    if (z80_interrupt(&cpu)) { irq_pending = 0; ga_counter &= 31; cpu.cycles = b2 + 20; }
                }
            }
            memcpy(line_pal[scan], ga_palette, 17);
            line_mode[scan] = ga_mode;
            line_start[scan] = (crtc[12] << 8) | crtc[13];
            /* HSYNC */
            if (++ga_counter == 52) { ga_counter = 0; irq_pending = 1; }
            if (scan == 1) { if (ga_counter >= 32) irq_pending = 1; ga_counter = 0; }
        }
        uint8_t rec[16];
        memcpy(rec, psg, 14);
        rec[14] = psg_written[13]; /* envelope restarted this frame */
        rec[15] = 0;
        fwrite(rec, 1, 16, psg_log);
        if (in_list(shots, frame) || (shot_every && frame % shot_every == 0)) save_screen(dir, frame);
        if (in_list(dumps, frame)) dump_state(dir, frame);
    }
    snprintf(name, sizeof name, "%s/coverage.bin", dir);
    FILE *cf = fopen(name, "wb"); fwrite(coverage, 1, 65536, cf); fclose(cf);
    for (int i = 0; i < 65536; i++) if (ur_count[i]) fprintf(log_file, "reader %04x %u\n", i, ur_count[i]);
    for (int i = 0; i < 65536; i++) if (uw_count[i]) fprintf(log_file, "writer %04x %u\n", i, uw_count[i]);
    fclose(psg_log); fclose(log_file);
    if (trace) fclose(trace);
    fprintf(stderr, "pc=%04x sp=%04x\n", cpu.pc, cpu.sp);
    return 0;
}
