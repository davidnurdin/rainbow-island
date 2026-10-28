/* uPD765 floppy controller, polled mode (what the CPC uses): one drive, one side. */
static uint8_t fdc_cmd[9], fdc_res[7], fdc_track, fdc_seek_done;
static int fdc_phase, fdc_cmd_len, fdc_cmd_pos, fdc_res_len, fdc_res_pos, fdc_data_pos;
enum { FDC_IDLE, FDC_COMMAND, FDC_READ, FDC_WRITE, FDC_RESULT };

static int fdc_command_length(uint8_t c) {
    switch (c & 0x1f) {
    case 0x03: return 3; case 0x04: return 2; case 0x05: case 0x06: case 0x09: case 0x0c: return 9;
    case 0x07: return 2; case 0x08: return 1; case 0x0a: return 2; case 0x0f: return 3;
    default: return 1;
    }
}
static void fdc_result(int n) { fdc_res_len = n; fdc_res_pos = 0; fdc_phase = FDC_RESULT; }
static void fdc_rw_result(uint8_t st0, uint8_t st1) {
    fdc_res[0] = st0; fdc_res[1] = st1; fdc_res[2] = 0;
    fdc_res[3] = fdc_cmd[2]; fdc_res[4] = fdc_cmd[3]; fdc_res[5] = fdc_cmd[4]; fdc_res[6] = fdc_cmd[5];
    fdc_result(7);
}
static int fdc_sector_ok(void) {
    return fdc_cmd[2] == fdc_track && fdc_track < 40 && fdc_cmd[4] >= 0x41 && fdc_cmd[4] <= 0x49;
}
static void fdc_execute(void) {
    uint8_t c = fdc_cmd[0] & 0x1f;
    fprintf(log_file, "fdc cmd"); for (int i = 0; i < fdc_cmd_len; i++) fprintf(log_file, " %02x", fdc_cmd[i]); fprintf(log_file, " pc=%04x\n", cpu.pc);
    switch (c) {
    case 0x03: fdc_phase = FDC_IDLE; break;
    case 0x04: fdc_res[0] = 0x20 | (fdc_track == 0 ? 0x10 : 0); fdc_result(1); break;
    case 0x07: fdc_track = 0; fdc_seek_done = 1; fdc_phase = FDC_IDLE; break;
    case 0x0f: fdc_track = fdc_cmd[2]; fdc_seek_done = 1; fdc_phase = FDC_IDLE; break;
    case 0x08:
        if (fdc_seek_done) { fdc_res[0] = 0x20; fdc_res[1] = fdc_track; fdc_seek_done = 0; fdc_result(2); }
        else { fdc_res[0] = 0x80; fdc_result(1); }
        break;
    case 0x0a:
        fdc_res[0] = 0; fdc_res[1] = 0; fdc_res[2] = 0; fdc_res[3] = fdc_track; fdc_res[4] = 0; fdc_res[5] = 0x41; fdc_res[6] = 2;
        fdc_result(7);
        break;
    case 0x06: case 0x0c: case 0x05: case 0x09:
        if (!fdc_sector_ok()) { fdc_rw_result(0x40, 0x04); break; }
        fdc_data_pos = 0;
        fdc_phase = (c == 0x05 || c == 0x09) ? FDC_WRITE : FDC_READ;
        fprintf(log_file, "fdc %s track %d sector %02x pc=%04x\n", fdc_phase == FDC_READ ? "read" : "write", fdc_cmd[2], fdc_cmd[4], cpu.pc);
        break;
    default: fdc_res[0] = 0x80; fdc_result(1);
    }
}
static void fdc_next_sector(void) {
    if (fdc_cmd[4] == fdc_cmd[6]) { fdc_rw_result(0x40, 0x80); return; } /* end of cylinder: no TC on the CPC */
    fdc_cmd[4]++;
    if (!fdc_sector_ok()) { fdc_rw_result(0x40, 0x04); return; }
    fdc_data_pos = 0;
}
static uint8_t fdc_status(void) {
    switch (fdc_phase) {
    case FDC_COMMAND: return 0x90;
    case FDC_READ: return 0xf0;
    case FDC_WRITE: return 0xb0;
    case FDC_RESULT: return 0xd0;
    }
    return 0x80;
}
static uint8_t fdc_read_data(void) {
    if (fdc_phase == FDC_READ) {
        if (fdc_data_pos == 0) fdc_first_byte = 1;
        uint8_t v = disk_sectors[fdc_track][fdc_cmd[4] - 0x41][fdc_data_pos++];
        if (fdc_data_pos == 512) fdc_next_sector();
        return v;
    }
    if (fdc_phase == FDC_RESULT) {
        uint8_t v = fdc_res[fdc_res_pos++];
        if (fdc_res_pos >= fdc_res_len) fdc_phase = FDC_IDLE;
        return v;
    }
    return 0xff;
}
static void fdc_write_data(uint8_t v) {
    switch (fdc_phase) {
    case FDC_IDLE:
        fdc_cmd[0] = v; fdc_cmd_len = fdc_command_length(v); fdc_cmd_pos = 1;
        if (fdc_cmd_len == 1) fdc_execute(); else fdc_phase = FDC_COMMAND;
        break;
    case FDC_COMMAND:
        fdc_cmd[fdc_cmd_pos++] = v;
        if (fdc_cmd_pos == fdc_cmd_len) fdc_execute();
        break;
    case FDC_WRITE:
        disk_sectors[fdc_track][fdc_cmd[4] - 0x41][fdc_data_pos++] = v;
        if (fdc_data_pos == 512) fdc_next_sector();
        break;
    }
}
